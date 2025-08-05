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

            // Primeiro, identificar todos os canais de pagamentos que a conexão está inscrita
            $channels = $this->channels->all();
            
            // Log reduzido - apenas informações essenciais
            
            // Verificar se a conexão está inscrita em algum canal
            $connectionSubscribedToChannels = false;
            foreach ($channels as $channel) {
                $channelConnections = $this->channels->connections($channel->name());
                
                foreach ($channelConnections as $channelConnection) {
                    if ($channelConnection->id() === $connection->id()) {
                        $connectionSubscribedToChannels = true;
                        $channelName = $channel->name();
                        $unsubscribedChannels[] = $channelName;
                        
                        // Se for um canal de pagamentos individual, adicionar à lista para verificação posterior
                        if (str_starts_with($channelName, 'payments-channel-')) {
                            $paymentChannelsToCheck[] = $channelName;
                            $machineId = intval(str_replace('payments-channel-', '', $channelName));
                            $machineIdsToCheck[] = $machineId;
                        }
                        // Se for um canal de pagamentos geral, também adicionar para verificação
                        // elseif (str_starts_with($channelName, 'paymentsAll-channel-')) {
                        //     $paymentChannelsToCheck[] = $channelName;
                        // }
                    }
                }
            }
            
            // Se a conexão não está inscrita em nenhum canal, verificar se há máquinas que precisam ser marcadas como offline
            // Isso pode acontecer quando a conexão já foi desinscrita antes do método close ser chamado
            if (!$connectionSubscribedToChannels) {
                
                // Verificar todos os canais de pagamentos individuais para ver se algum ficou sem conexões
                foreach ($channels as $channel) {
                    $channelName = $channel->name();
                    
                    if (str_starts_with($channelName, 'payments-channel-')) {
                        $channelConnections = $this->channels->connections($channelName);
                        
                        // Se não há conexões neste canal, marcar a máquina como offline
                        if (empty($channelConnections)) {
                            $machineId = intval(str_replace('payments-channel-', '', $channelName));
                            
                            try {
                                $machineService->setMachineOffline($machineId);
                                LogTETE::info('Máquina marcada como offline (verificação geral)', [
                                    'machine_id' => $machineId,
                                ]);
                            } catch (Exception $e) {
                                LogTETE::error('Erro ao marcar máquina como offline', [
                                    'machine_id' => $machineId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                }
                
                // Verificação adicional: se não há nenhum canal de pagamentos individual ativo,
                // verificar se há máquinas que podem ter sido desconectadas mas não foram marcadas como offline
                $activePaymentChannels = [];
                foreach ($channels as $channel) {
                    $channelName = $channel->name();
                    if (str_starts_with($channelName, 'payments-channel-')) {
                        $channelConnections = $this->channels->connections($channelName);
                        if (!empty($channelConnections)) {
                            $activePaymentChannels[] = $channelName;
                        }
                    }
                }
                
                // Log removido - informação não essencial
            }

            // Log removido para reduzir poluição

            // Desinscrever de todos os canais
            foreach ($unsubscribedChannels as $channelName) {
                $this->channels->unsubscribe($connection, $channelName);
            }

            // Pequena pausa para garantir que a desinscrição foi processada
            usleep(1000); // 1ms

            // Agora verificar se algum canal de pagamentos ficou sem conexões
            foreach ($paymentChannelsToCheck as $channelName) {
                // Verificar se o canal ainda existe
                $channel = $this->channels->find($channelName);
                
                if (!$channel) {
                    // Se for um canal de pagamentos individual, marcar a máquina como offline
                    if (str_starts_with($channelName, 'payments-channel-')) {
                        $machineId = intval(str_replace('payments-channel-', '', $channelName));
                        
                        try {
                            $machineService->setMachineOffline($machineId);
                            LogTETE::info('Máquina marcada como offline (canal removido)', [
                                'machine_id' => $machineId,
                            ]);
                        } catch (Exception $e) {
                            LogTETE::error('Erro ao marcar máquina como offline', [
                                'machine_id' => $machineId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                    continue;
                }
                
                $remainingConnections = $this->channels->connections($channelName);
                
                // Se não há mais conexões no canal, marcar a máquina como offline
                if (empty($remainingConnections)) {
                    if (str_starts_with($channelName, 'payments-channel-')) {
                        $machineId = intval(str_replace('payments-channel-', '', $channelName));
                        
                        try {
                            $machineService->setMachineOffline($machineId);
                            LogTETE::info('Máquina marcada como offline (sem conexões)', [
                                'machine_id' => $machineId,
                            ]);
                        } catch (Exception $e) {
                            LogTETE::error('Erro ao marcar máquina como offline', [
                                'machine_id' => $machineId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                }
            }

            // Verificação adicional: se temos machine IDs específicos para verificar,
            // verificar se ainda existem conexões ativas para essas máquinas
            foreach ($machineIdsToCheck as $machineId) {
                $machineChannelName = "payments-channel-{$machineId}";
                $machineChannel = $this->channels->find($machineChannelName);
                
                if (!$machineChannel) {
                    continue;
                }
                
                $machineConnections = $this->channels->connections($machineChannelName);
                
                // Se não há mais conexões para esta máquina específica, marcá-la como offline
                if (empty($machineConnections)) {
                    try {
                        $machineService->setMachineOffline($machineId);
                        LogTETE::info('Máquina marcada como offline (verificação adicional)', [
                            'machine_id' => $machineId,
                        ]);
                    } catch (Exception $e) {
                        LogTETE::error('Erro ao marcar máquina como offline', [
                            'machine_id' => $machineId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // VERIFICAÇÃO FINAL COMPLETA: Verificar todos os canais de pagamentos após a desconexão
            $paymentChannelsStatus = $this->getPaymentChannelsStatus();
            
            // Se não há canais de pagamento ativos, pode ser que todas as máquinas tenham sido desconectadas
            if (empty($paymentChannelsStatus)) {
                // Verificar se há máquinas que estavam conectadas mas agora não têm mais canais
                $connectedMachineIds = $this->getConnectedMachineIds();
                if (!empty($connectedMachineIds)) {
                    foreach ($connectedMachineIds as $machineId) {
                        try {
                            $machineService->setMachineOffline($machineId);
                            LogTETE::info('Máquina marcada como offline (sem canais ativos)', [
                                'machine_id' => $machineId,
                                'connection_id' => $connection->id(),
                            ]);
                        } catch (Exception $e) {
                            LogTETE::error('Erro ao marcar máquina como offline (sem canais ativos)', [
                                'machine_id' => $machineId,
                                'connection_id' => $connection->id(),
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                } else {
                    // Se não há canais de pagamento ativos, verificar se há máquinas que estavam conectadas ANTES da desconexão
                    if (!empty($machinesConnectedBefore)) {
                        foreach ($machinesConnectedBefore as $machineId) {
                            try {
                                $machineService->setMachineOffline($machineId);
                                LogTETE::info('Máquina marcada como offline (estava conectada antes da desconexão)', [
                                    'machine_id' => $machineId,
                                    'connection_id' => $connection->id(),
                                ]);
                            } catch (Exception $e) {
                                LogTETE::error('Erro ao marcar máquina como offline (estava conectada antes da desconexão)', [
                                    'machine_id' => $machineId,
                                    'connection_id' => $connection->id(),
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    } else {
                        // Se não há canais de pagamento ativos, registrar para debug
                        LogTETE::info('Nenhum canal de pagamento ativo encontrado', [
                            'connection_id' => $connection->id(),
                            'total_payment_channels' => 0,
                        ]);
                    }
                }
            } else {
                // Verificar quais máquinas ficaram sem conexões e marcá-las como offline
                $machinesToMarkOffline = [];
                foreach ($paymentChannelsStatus as $channelStatus) {
                    if (!$channelStatus['has_connections']) {
                        $machinesToMarkOffline[] = $channelStatus['machine_id'];
                    }
                }
                
                // Marcar como offline apenas as máquinas que realmente não têm mais conexões
                foreach ($machinesToMarkOffline as $machineId) {
                    try {
                        $machineService->setMachineOffline($machineId);
                        LogTETE::info('Máquina marcada como offline (verificação final completa)', [
                            'machine_id' => $machineId,
                            'connection_id' => $connection->id(),
                        ]);
                    } catch (Exception $e) {
                        LogTETE::error('Erro ao marcar máquina como offline (verificação final)', [
                            'machine_id' => $machineId,
                            'connection_id' => $connection->id(),
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

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
