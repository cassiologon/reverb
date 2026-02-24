<?php

namespace Laravel\Reverb\Protocols\Pusher;

use Exception;
use Illuminate\Support\Str;
use Laravel\Reverb\Contracts\Connection;
use Laravel\Reverb\Events\MessageReceived;
use Laravel\Reverb\Loggers\Log;
use Laravel\Reverb\Protocols\Pusher\Contracts\ChannelManager;
use Laravel\Reverb\Protocols\Pusher\Exceptions\InvalidOrigin;
use Laravel\Reverb\Protocols\Pusher\Exceptions\PusherException;
use Illuminate\Support\Facades\Log as LogTETE;
use App\Services\MachineService;

class Server
{
    /**
     * Channel name patterns for machine detection.
     */
    private const LEGACY_PREFIX = 'payments-channel-';
    private const SECURE_PREFIX = 'private-payments-channel-secure.';

    /**
     * Create a new server instance.
     */
    public function __construct(
        protected ChannelManager $channels, 
        protected EventHandler $handler,
        protected ?MachineService $machineService = null
    ) {
        //
    }

    /**
     * Extract machine ID from a payment channel name (legacy or secure).
     * Returns null if the channel is not a payment channel.
     */
    protected function extractMachineId(string $channelName): ?int
    {
        if (str_starts_with($channelName, self::SECURE_PREFIX)) {
            return intval(str_replace(self::SECURE_PREFIX, '', $channelName));
        }

        if (str_starts_with($channelName, self::LEGACY_PREFIX)) {
            $suffix = str_replace(self::LEGACY_PREFIX, '', $channelName);
            if (is_numeric($suffix)) {
                return intval($suffix);
            }
        }

        return null;
    }

    /**
     * Check if a channel name is a payment channel (legacy or secure).
     */
    protected function isPaymentChannel(string $channelName): bool
    {
        return $this->extractMachineId($channelName) !== null;
    }

    /**
     * Handle the a client connection.
     */
    public function open(Connection $connection): void
    {
        try {
            $this->verifyOrigin($connection);

            $connection->touch();

            $this->handler->handle($connection, 'pusher:connection_established');

            Log::info('Connection Established', $connection->id());
        } catch (Exception $e) {
            $this->error($connection, $e);
        }
    }

    /**
     * Handle a new message received by the connected client.
     */
    public function message(Connection $from, string $message): void
    {
        Log::info('Message Received', $from->id());
        Log::message($message);

        $from->touch();

        try {
            $event = json_decode($message, associative: true, flags: JSON_THROW_ON_ERROR);

            if (isset($event['event']) && ($event['event'] === 'pusher:subscribe' || $event['event'] === 'pusher:subscription_succeeded')) {
                $channelName = $event['data']['channel'] ?? '';
                $machineId = $this->extractMachineId($channelName);

                if ($machineId !== null) {
                    $machineService = $this->machineService ?? new MachineService();
                    $machineService->setMachineOnline($machineId);

                    LogTETE::info('Machine set to online via subscription', [
                        'machine_id' => $machineId,
                        'channel' => $channelName,
                        'connection_id' => $from->id(),
                    ]);
                }
            }

            match (Str::startsWith($event['event'], 'pusher:')) {
                true => $this->handler->handle(
                    $from,
                    $event['event'],
                    empty($event['data']) ? [] : $event['data'],
                    $event['channel'] ?? null
                ),
                default => ClientEvent::handle($from, $event)
            };

            Log::info('Message Handled', $from->id());

            MessageReceived::dispatch($from, $message);
        } catch (Exception $e) {
            $this->error($from, $e);
        }
    }

    /**
     * Get all machine IDs that are currently connected through payment channels.
     */
    protected function getConnectedMachineIds(): array
    {
        $connectedMachineIds = [];
        $channels = $this->channels->all();
        
        foreach ($channels as $channel) {
            $channelName = $channel->name();
            $machineId = $this->extractMachineId($channelName);

            if ($machineId !== null) {
                $channelConnections = $this->channels->connections($channelName);
                
                if (!empty($channelConnections)) {
                    $connectedMachineIds[] = $machineId;
                }
            }
        }
        
        return array_unique($connectedMachineIds);
    }

    /**
     * Get detailed information about all payment channels and their connections.
     */
    protected function getPaymentChannelsStatus(): array
    {
        $channelsStatus = [];
        $channels = $this->channels->all();
        
        foreach ($channels as $channel) {
            $channelName = $channel->name();
            $machineId = $this->extractMachineId($channelName);

            if ($machineId !== null) {
                $channelConnections = $this->channels->connections($channelName);
                
                $channelsStatus[] = [
                    'channel_name' => $channelName,
                    'machine_id' => $machineId,
                    'connection_count' => count($channelConnections),
                    'has_connections' => !empty($channelConnections),
                    'connection_ids' => array_map(fn($conn) => $conn->id(), $channelConnections)
                ];
            }
        }
        
        return $channelsStatus;
    }

    /**
     * Check if a specific machine is still connected through its payment channel.
     */
    protected function isMachineConnected(int $machineId): bool
    {
        $channelNames = [
            self::LEGACY_PREFIX . $machineId,
            self::SECURE_PREFIX . $machineId,
        ];

        foreach ($channelNames as $channelName) {
            $channel = $this->channels->find($channelName);
            if ($channel) {
                $channelConnections = $this->channels->connections($channelName);
                if (!empty($channelConnections)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Detect silent disconnections by comparing previous and current machine states.
     * This method is optimized for large scale operations.
     */
    protected function detectSilentDisconnections(Connection $connection, array $machinesConnectedBefore, MachineService $machineService): void
    {
        if (empty($machinesConnectedBefore)) {
            return;
        }

        // Get current connected machines
        $currentConnectedMachines = $this->getConnectedMachineIds();
        
        // Find machines that were connected before but are not connected now
        $silentDisconnections = array_diff($machinesConnectedBefore, $currentConnectedMachines);
        
        if (!empty($silentDisconnections)) {
            LogTETE::info('Detectadas desconexões silenciosas', [
                'connection_id' => $connection->id(),
                'machines_connected_before' => $machinesConnectedBefore,
                'current_connected_machines' => $currentConnectedMachines,
                'silent_disconnections' => $silentDisconnections,
                'count' => count($silentDisconnections),
            ]);
            
            // Mark silently disconnected machines as offline
            foreach ($silentDisconnections as $machineId) {
                try {
                    $machineService->setMachineOffline($machineId);
                    LogTETE::info('Máquina marcada como offline (desconexão silenciosa)', [
                        'machine_id' => $machineId,
                        'connection_id' => $connection->id(),
                    ]);
                } catch (Exception $e) {
                    LogTETE::error('Erro ao marcar máquina como offline (desconexão silenciosa)', [
                        'machine_id' => $machineId,
                        'connection_id' => $connection->id(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Perform periodic cleanup of offline machines.
     * This method should be called periodically to ensure machines are properly marked as offline.
     */
    public function performPeriodicCleanup(): void
    {
        try {
            $machineService = $this->machineService ?? new MachineService();
            $allConnectedMachines = $this->getConnectedMachineIds();
            
            // Get all machines that should be online from the database
            $onlineMachinesFromDb = $machineService->getOnlineMachines();
            
            // Find machines that are marked as online in DB but not connected
            $machinesToMarkOffline = array_diff($onlineMachinesFromDb, $allConnectedMachines);
            
            if (!empty($machinesToMarkOffline)) {
                LogTETE::info('Limpeza periódica - máquinas para marcar como offline', [
                    'machines_to_mark_offline' => $machinesToMarkOffline,
                    'count' => count($machinesToMarkOffline),
                ]);
                
                foreach ($machinesToMarkOffline as $machineId) {
                    try {
                        $machineService->setMachineOffline($machineId);
                        LogTETE::info('Máquina marcada como offline (limpeza periódica)', [
                            'machine_id' => $machineId,
                        ]);
                    } catch (Exception $e) {
                        LogTETE::error('Erro ao marcar máquina como offline (limpeza periódica)', [
                            'machine_id' => $machineId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (Exception $e) {
            LogTETE::error('Erro durante limpeza periódica', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Final check to ensure all machines are properly marked as offline if they have no connections.
     */
    protected function finalMachineStatusCheck(Connection $connection, MachineService $machineService): void
    {
        try {
            $paymentChannelsStatus = $this->getPaymentChannelsStatus();
            $checkedMachines = [];

            foreach ($paymentChannelsStatus as $channelStatus) {
                $mid = $channelStatus['machine_id'];
                if (isset($checkedMachines[$mid])) {
                    continue;
                }
                $checkedMachines[$mid] = true;

                if (!$this->isMachineConnected($mid)) {
                    try {
                        $machineService->setMachineOffline($mid);
                        LogTETE::info('Máquina marcada como offline (verificação final)', [
                            'machine_id' => $mid,
                            'connection_id' => $connection->id(),
                        ]);
                    } catch (Exception $e) {
                        LogTETE::error('Erro ao marcar máquina como offline (verificação final)', [
                            'machine_id' => $mid,
                            'connection_id' => $connection->id(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        } catch (Exception $e) {
            LogTETE::error('Erro na verificação final de status das máquinas', [
                'connection_id' => $connection->id(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle a client disconnection.
     */
    public function close(Connection $connection): void
    {
        try {
            $machineService = $this->machineService ?? new MachineService();
            $unsubscribedChannels = [];
            $paymentChannelsToCheck = [];
            $machineIdsToCheck = [];
            
            // Capturar o estado das máquinas conectadas ANTES da desconexão
            $machinesConnectedBefore = $this->getConnectedMachineIds();
            
            // Log otimizado - apenas quando há mudanças significativas
            if (!empty($machinesConnectedBefore)) {
                LogTETE::info('Iniciando processo de desconexão', [
                    'connection_id' => $connection->id(),
                    'machines_connected_before' => $machinesConnectedBefore,
                    'total_machines_connected' => count($machinesConnectedBefore),
                ]);
            }

            $channels = $this->channels->all();
            $connectionSubscribedToChannels = false;
            
            foreach ($channels as $channel) {
                $channelName = $channel->name();
                $machineId = $this->extractMachineId($channelName);

                if ($machineId !== null) {
                    $channelConnections = $this->channels->connections($channelName);
                    
                    foreach ($channelConnections as $channelConnection) {
                        if ($channelConnection->id() === $connection->id()) {
                            $connectionSubscribedToChannels = true;
                            $unsubscribedChannels[] = $channelName;
                            $paymentChannelsToCheck[] = $channelName;
                            $machineIdsToCheck[] = $machineId;
                            break;
                        }
                    }
                }
            }
            
            // Desinscrever de todos os canais
            foreach ($unsubscribedChannels as $channelName) {
                $this->channels->unsubscribe($connection, $channelName);
            }

            // Verificar canais de pagamentos que ficaram sem conexões
            $machinesToMarkOffline = [];
            
            foreach ($paymentChannelsToCheck as $channelName) {
                $machineId = $this->extractMachineId($channelName);
                if ($machineId === null) {
                    continue;
                }

                $channel = $this->channels->find($channelName);
                
                if (!$channel) {
                    if (!$this->isMachineConnected($machineId)) {
                        $machinesToMarkOffline[] = $machineId;
                    }
                    continue;
                }
                
                $remainingConnections = $this->channels->connections($channelName);
                
                if (empty($remainingConnections) && !$this->isMachineConnected($machineId)) {
                    $machinesToMarkOffline[] = $machineId;
                }
            }

            // Marcar máquinas como offline
            foreach ($machinesToMarkOffline as $machineId) {
                try {
                    $machineService->setMachineOffline($machineId);
                    LogTETE::info('Máquina marcada como offline', [
                        'machine_id' => $machineId,
                        'connection_id' => $connection->id(),
                    ]);
                } catch (Exception $e) {
                    LogTETE::error('Erro ao marcar máquina como offline', [
                        'machine_id' => $machineId,
                        'connection_id' => $connection->id(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
            
            // DETECÇÃO DE DESCONEXÕES SILENCIOSAS - Lógica otimizada
            $this->detectSilentDisconnections($connection, $machinesConnectedBefore, $machineService);
            
            // VERIFICAÇÃO FINAL: Verificar se há máquinas que perderam todas as conexões
            $this->finalMachineStatusCheck($connection, $machineService);

            // Desconectar a conexão
            $connection->disconnect();
        } catch (Exception $e) {
            // Garantir que a conexão seja desconectada mesmo em caso de erro
            $connection->disconnect();
        }
    }


    /**
     * Handle an error.
     */
    public function error(Connection $connection, Exception $exception): void
    {
        if ($exception instanceof PusherException) {
            $connection->send(json_encode($exception->payload()));

            Log::error('Message from '.$connection->id().' resulted in a pusher error');
            Log::info($exception->getMessage());

            return;
        }

        $connection->send(json_encode([
            'event' => 'pusher:error',
            'data' => json_encode([
                'code' => 4200,
                'message' => 'Invalid message format',
            ]),
        ]));

        Log::error('Message from '.$connection->id().' resulted in an unknown error');
        Log::info($exception->getMessage());
    }

    /**
     * Verify the origin of the connection.
     *
     * @throws \Laravel\Reverb\Exceptions\InvalidOrigin
     */
    protected function verifyOrigin(Connection $connection): void
    {
        $allowedOrigins = $connection->app()->allowedOrigins();

        if (in_array('*', $allowedOrigins)) {
            return;
        }

        $origin = parse_url($connection->origin(), PHP_URL_HOST);

        foreach ($allowedOrigins as $allowedOrigin) {
            if (Str::is($allowedOrigin, $origin)) {
                return;
            }
        }

        throw new InvalidOrigin;
    }
}
