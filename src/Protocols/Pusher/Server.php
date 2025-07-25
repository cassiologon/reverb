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
    
                // Verificar se o canal é um canal de pagamentos
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
     * Handle a client disconnection.
     */
    public function close(Connection $connection): void
    {
        try {
            $machineService = $this->machineService ?? new MachineService();
            $unsubscribedChannels = [];
            $paymentChannelsToCheck = [];

            // Primeiro, identificar todos os canais de pagamentos que a conexão está inscrita
            $channels = $this->channels->all();
            
            LogTETE::info('Iniciando processo de desconexão', [
                'connection_id' => $connection->id(),
                'total_channels' => count($channels),
            ]);
            
            foreach ($channels as $channel) {
                $channelConnections = $this->channels->connections($channel->name());
                
                foreach ($channelConnections as $channelConnection) {
                    if ($channelConnection->id() === $connection->id()) {
                        $channelName = $channel->name();
                        $unsubscribedChannels[] = $channelName;
                        
                        // Se for um canal de pagamentos, adicionar à lista para verificação posterior
                        if (str_starts_with($channelName, 'payments-channel-')) {
                            $paymentChannelsToCheck[] = $channelName;
                        }
                    }
                }
            }

            LogTETE::info('Canais identificados para desconexão', [
                'connection_id' => $connection->id(),
                'unsubscribed_channels' => $unsubscribedChannels,
                'payment_channels_to_check' => $paymentChannelsToCheck,
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
                    // Se o canal não existe mais, significa que não há mais conexões ativas
                    // Portanto, devemos marcar a máquina como offline
                    $machineId = intval(str_replace('payments-channel-', '', $channelName));
                    
                    LogTETE::info('Canal não encontrado após desinscrição - marcando máquina como offline', [
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
                        LogTETE::error('Erro ao marcar máquina como offline (canal removido)', [
                            'machine_id' => $machineId,
                            'error' => $e->getMessage(),
                        ]);
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
                    $machineId = intval(str_replace('payments-channel-', '', $channelName));
                    
                    LogTETE::info('Canal de pagamentos sem conexões, marcando máquina como offline', [
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

            // Desconectar a conexão
            $connection->disconnect();

            // Só logar se houver canais de pagamentos envolvidos
            if (!empty($paymentChannelsToCheck)) {
                Log::info('Connection Closed', $connection->id());
                LogTETE::info('Connection Closed', [
                    'connection_id' => $connection->id(),
                    'unsubscribed_channels' => $unsubscribedChannels,
                    'payment_channels_checked' => $paymentChannelsToCheck,
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
