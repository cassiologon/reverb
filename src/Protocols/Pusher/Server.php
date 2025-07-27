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
                // Verificar se o canal é um canal de pagamentos geral
                elseif (str_starts_with($channelName, 'paymentsAll-channel-')) {
                    // Para canais gerais, não podemos determinar qual máquina específica
                    // mas podemos registrar que uma conexão se inscreveu em um canal de pagamentos
                    LogTETE::info('Connection subscribed to general payments channel', [
                        'channel_name' => $channelName,
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

            // Primeiro, identificar todos os canais de pagamentos que a conexão está inscrita
            $channels = $this->channels->all();
            
            LogTETE::info('Iniciando processo de desconexão', [
                'connection_id' => $connection->id(),
                'total_channels' => count($channels),
            ]);
            
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
                        elseif (str_starts_with($channelName, 'paymentsAll-channel-')) {
                            $paymentChannelsToCheck[] = $channelName;
                        }
                    }
                }
            }
            
            // Se a conexão não está inscrita em nenhum canal, verificar se há máquinas que precisam ser marcadas como offline
            // Isso pode acontecer quando a conexão já foi desinscrita antes do método close ser chamado
            if (!$connectionSubscribedToChannels) {
                LogTETE::info('Conexão não está inscrita em nenhum canal, verificando máquinas que podem precisar ser marcadas como offline', [
                    'connection_id' => $connection->id(),
                ]);
                
                // Verificar todos os canais de pagamentos individuais para ver se algum ficou sem conexões
                foreach ($channels as $channel) {
                    $channelName = $channel->name();
                    
                    if (str_starts_with($channelName, 'payments-channel-')) {
                        $channelConnections = $this->channels->connections($channelName);
                        
                        // Se não há conexões neste canal, marcar a máquina como offline
                        if (empty($channelConnections)) {
                            $machineId = intval(str_replace('payments-channel-', '', $channelName));
                            
                            LogTETE::info('Canal de pagamentos individual sem conexões encontrado durante verificação geral', [
                                'channel_name' => $channelName,
                                'machine_id' => $machineId,
                                'connection_id' => $connection->id(),
                            ]);
                            
                            try {
                                $machineService->setMachineOffline($machineId);
                                LogTETE::info('Máquina marcada como offline durante verificação geral', [
                                    'machine_id' => $machineId,
                                ]);
                            } catch (Exception $e) {
                                LogTETE::error('Erro ao marcar máquina como offline durante verificação geral', [
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
                
                if (empty($activePaymentChannels)) {
                    LogTETE::info('Nenhum canal de pagamentos individual ativo encontrado', [
                        'connection_id' => $connection->id(),
                    ]);
                }
            }

            LogTETE::info('Canais identificados para desconexão', [
                'connection_id' => $connection->id(),
                'unsubscribed_channels' => $unsubscribedChannels,
                'payment_channels_to_check' => $paymentChannelsToCheck,
                'machine_ids_to_check' => $machineIdsToCheck,
            ]);

            // Desinscrever de todos os canais
            foreach ($unsubscribedChannels as $channelName) {
                $this->channels->unsubscribe($connection, $channelName);
                LogTETE::info('Desinscrito do canal', [
                    'connection_id' => $connection->id(),
                    'channel_name' => $channelName,
                ]);
            }

            // Pequena pausa para garantir que a desinscrição foi processada
            usleep(1000); // 1ms

            // Agora verificar se algum canal de pagamentos ficou sem conexões
            foreach ($paymentChannelsToCheck as $channelName) {
                // Verificar se o canal ainda existe
                $channel = $this->channels->find($channelName);
                
                if (!$channel) {
                    LogTETE::info('Canal não encontrado após desinscrição', [
                        'channel_name' => $channelName,
                        'connection_id' => $connection->id(),
                    ]);
                    
                    // Se for um canal de pagamentos individual, marcar a máquina como offline
                    if (str_starts_with($channelName, 'payments-channel-')) {
                        $machineId = intval(str_replace('payments-channel-', '', $channelName));
                        
                        LogTETE::info('Canal de pagamentos individual removido - marcando máquina como offline', [
                            'channel_name' => $channelName,
                            'machine_id' => $machineId,
                            'connection_id' => $connection->id(),
                        ]);
                        
                        try {
                            $machineService->setMachineOffline($machineId);
                            LogTETE::info('Máquina marcada como offline com sucesso (canal removido)', [
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
                
                LogTETE::info('Verificando conexões restantes no canal', [
                    'channel_name' => $channelName,
                    'remaining_connections_count' => count($remainingConnections),
                    'connection_id' => $connection->id(),
                    'remaining_connection_ids' => array_keys($remainingConnections),
                ]);
                
                // Se não há mais conexões no canal, marcar a máquina como offline
                if (empty($remainingConnections)) {
                    if (str_starts_with($channelName, 'payments-channel-')) {
                        $machineId = intval(str_replace('payments-channel-', '', $channelName));
                        
                        LogTETE::info('Canal de pagamentos individual sem conexões, marcando máquina como offline', [
                            'machine_id' => $machineId,
                            'channel_name' => $channelName,
                            'connection_id' => $connection->id(),
                            'remaining_connections_count' => count($remainingConnections),
                        ]);
                        
                        try {
                            $machineService->setMachineOffline($machineId);
                            LogTETE::info('Máquina marcada como offline com sucesso', [
                                'machine_id' => $machineId,
                            ]);
                        } catch (Exception $e) {
                            LogTETE::error('Erro ao marcar máquina como offline', [
                                'machine_id' => $machineId,
                                'error' => $e->getMessage(),
                            ]);
                        }
                    }
                } else {
                    // Log para debug - mostrar quantas conexões ainda existem
                    LogTETE::info('Canal de pagamentos ainda tem conexões ativas', [
                        'channel_name' => $channelName,
                        'remaining_connections_count' => count($remainingConnections),
                        'connection_id' => $connection->id(),
                        'remaining_connection_ids' => array_keys($remainingConnections),
                    ]);
                }
            }

            // Verificação adicional: se temos machine IDs específicos para verificar,
            // verificar se ainda existem conexões ativas para essas máquinas
            foreach ($machineIdsToCheck as $machineId) {
                $machineChannelName = "payments-channel-{$machineId}";
                $machineChannel = $this->channels->find($machineChannelName);
                
                if (!$machineChannel) {
                    LogTETE::info('Verificação adicional: canal da máquina não encontrado', [
                        'machine_id' => $machineId,
                        'channel_name' => $machineChannelName,
                        'connection_id' => $connection->id(),
                    ]);
                    continue;
                }
                
                $machineConnections = $this->channels->connections($machineChannelName);
                
                LogTETE::info('Verificação adicional: conexões da máquina', [
                    'machine_id' => $machineId,
                    'channel_name' => $machineChannelName,
                    'connection_count' => count($machineConnections),
                    'connection_id' => $connection->id(),
                    'connection_ids' => array_keys($machineConnections),
                ]);
                
                // Se não há mais conexões para esta máquina específica, marcá-la como offline
                if (empty($machineConnections)) {
                    LogTETE::info('Verificação adicional: máquina sem conexões ativas, marcando como offline', [
                        'machine_id' => $machineId,
                        'channel_name' => $machineChannelName,
                        'connection_id' => $connection->id(),
                    ]);
                    
                    try {
                        $machineService->setMachineOffline($machineId);
                        LogTETE::info('Máquina marcada como offline via verificação adicional', [
                            'machine_id' => $machineId,
                        ]);
                    } catch (Exception $e) {
                        LogTETE::error('Erro ao marcar máquina como offline via verificação adicional', [
                            'machine_id' => $machineId,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Verificação final: garantir que todas as máquinas que não têm conexões ativas sejam marcadas como offline
            $connectedMachineIds = $this->getConnectedMachineIds();
            LogTETE::info('Verificação final: máquinas conectadas após desconexão', [
                'connection_id' => $connection->id(),
                'connected_machine_ids' => $connectedMachineIds,
            ]);

            // Desconectar a conexão
            $connection->disconnect();

            // Só logar se houver canais de pagamentos envolvidos
            if (!empty($paymentChannelsToCheck)) {
                Log::info('Connection Closed', $connection->id());
                LogTETE::info('Connection Closed', [
                    'connection_id' => $connection->id(),
                    'unsubscribed_channels' => $unsubscribedChannels,
                    'payment_channels_checked' => $paymentChannelsToCheck,
                    'machine_ids_checked' => $machineIdsToCheck,
                ]);
            }
        } catch (Exception $e) {
            LogTETE::error('Erro durante o processo de desconexão', [
                'connection_id' => $connection->id(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
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
