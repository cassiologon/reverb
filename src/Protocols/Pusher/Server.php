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

            if (isset($event['event']) && $event['event'] === 'pusher:subscribe' or $event['event'] === 'pusher:subscription_succeeded') {
                $channelName = $event['data']['channel'] ?? '';
    
                // Verificar se o canal é um canal de pagamentos individual
                if (str_starts_with($channelName, 'payments-channel-')) {
                    // Extrair o ID da máquina do nome do canal
                    $machineId = intval(str_replace('payments-channel-', '', $channelName));
    
                    // Chamar o serviço para definir a máquina como online
                    $machineService = $this->machineService ?? new MachineService();
                    $machineService->setMachineOnline($machineId);
    
                    // Registrar no log que a máquina foi marcada como online
                    LogTETE::info('Machine set to online via subscription', [
                        'machine_id' => $machineId,
                        'connection_id' => $from->id(),
                    ]);

                    try {
                        \App\Models\MachineLog::create([
                            'machine_id' => $machineId,
                            'type' => 'connection',
                            'title' => 'Máquina conectou via WebSocket',
                            'details' => ['connection_id' => $from->id()],
                            'created_at' => now(),
                        ]);
                    } catch (Exception $e) {
                        LogTETE::warning('Erro ao salvar log de conexão: ' . $e->getMessage());
                    }
                }
                // Removido log de paymentsAll-channel-
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
            
            if (str_starts_with($channelName, 'payments-channel-')) {
                $channelConnections = $this->channels->connections($channelName);
                
                if (!empty($channelConnections)) {
                    $machineId = intval(str_replace('payments-channel-', '', $channelName));
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
            
            if (str_starts_with($channelName, 'payments-channel-')) {
                $channelConnections = $this->channels->connections($channelName);
                $machineId = intval(str_replace('payments-channel-', '', $channelName));
                
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
        $machineChannelName = "payments-channel-{$machineId}";
        $channel = $this->channels->find($machineChannelName);
        
        if (!$channel) {
            return false;
        }
        
        $channelConnections = $this->channels->connections($machineChannelName);
        return !empty($channelConnections);
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
            // Get all payment channels and check their connection status
            $paymentChannelsStatus = $this->getPaymentChannelsStatus();
            
            foreach ($paymentChannelsStatus as $channelStatus) {
                if (!$channelStatus['has_connections']) {
                    try {
                        $machineService->setMachineOffline($channelStatus['machine_id']);
                        LogTETE::info('Máquina marcada como offline (verificação final)', [
                            'machine_id' => $channelStatus['machine_id'],
                            'connection_id' => $connection->id(),
                            'channel_name' => $channelStatus['channel_name'],
                        ]);
                    } catch (Exception $e) {
                        LogTETE::error('Erro ao marcar máquina como offline (verificação final)', [
                            'machine_id' => $channelStatus['machine_id'],
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

            // Otimização: identificar apenas canais de pagamentos que a conexão está inscrita
            $channels = $this->channels->all();
            $connectionSubscribedToChannels = false;
            
            foreach ($channels as $channel) {
                $channelName = $channel->name();
                
                // Focar apenas em canais de pagamentos para otimizar performance
                if (str_starts_with($channelName, 'payments-channel-')) {
                    $channelConnections = $this->channels->connections($channelName);
                    
                    foreach ($channelConnections as $channelConnection) {
                        if ($channelConnection->id() === $connection->id()) {
                            $connectionSubscribedToChannels = true;
                            $unsubscribedChannels[] = $channelName;
                            $paymentChannelsToCheck[] = $channelName;
                            $machineId = intval(str_replace('payments-channel-', '', $channelName));
                            $machineIdsToCheck[] = $machineId;
                            break; // Otimização: sair do loop interno
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
                $channel = $this->channels->find($channelName);
                
                if (!$channel) {
                    // Canal foi removido - máquina deve estar offline
                    $machineId = intval(str_replace('payments-channel-', '', $channelName));
                    $machinesToMarkOffline[] = $machineId;
                    continue;
                }
                
                $remainingConnections = $this->channels->connections($channelName);
                
                // Se não há mais conexões no canal, marcar a máquina como offline
                if (empty($remainingConnections)) {
                    $machineId = intval(str_replace('payments-channel-', '', $channelName));
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
