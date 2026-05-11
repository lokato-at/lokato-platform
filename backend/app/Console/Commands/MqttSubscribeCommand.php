<?php

namespace App\Console\Commands;

use App\Services\ScanIngestService;
use App\Support\AppLogger;
use Carbon\Carbon;
use Illuminate\Console\Command;
use PhpMqtt\Client\Facades\MQTT;
use Throwable;

class MqttSubscribeCommand extends Command
{
    protected $signature = 'mqtt:subscribe {--once} {--debug}';
    protected $description = 'Subscribe to scan topic and ingest scans safely.';

    public function handle(ScanIngestService $scanIngestService): int
    {
        $topic = (string) env('MQTT_TOPIC_SCAN', '/api/v1/scan');
        $qos = (int) env('MQTT_QOS', 0);
        $latWarn = (int) env('MQTT_LATENCY_WARN_MS', 3000);
        $baseClientId = (string) (env('MQTT_CLIENT_ID') ?: 'lokato-laravel-subscriber');
        $clientId = $baseClientId.'-'.getmypid();
        config(['mqtt-client.connections.default.client_id' => $clientId]);

        $this->info("MQTT subscribe starting: topic={$topic}, qos={$qos}, client_id={$clientId}");
        AppLogger::event('mqtt', 'mqtt_subscriber_starting', ['topic' => $topic, 'qos' => $qos, 'client_id' => $clientId], 'info', true);

        $mqtt = MQTT::connection();
        $mqtt->subscribe($topic, function (string $incomingTopic, string $message) use ($scanIngestService, $mqtt, $latWarn) {
            $mqttReceivedAt = now();

            if ($this->option('debug')) {
                $this->line("MQTT message received on {$incomingTopic}: ".substr($message, 0, 200));
            }

            try {
                $payload = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($payload)) {
                    AppLogger::event('mqtt', 'mqtt_message_ignored', ['reason' => 'payload_not_object', 'topic' => $incomingTopic], 'warning');
                    return;
                }

                $eventTimeIso = null;
                $scannerSentAt = null;
                $eventTimeRaw = isset($payload['event_time']) ? (string) $payload['event_time'] : null;
                if ($eventTimeRaw !== null && $eventTimeRaw !== '') {
                    try {
                        $scannerSentAt = Carbon::parse($eventTimeRaw);
                        $eventTimeIso = $scannerSentAt->toIso8601String();
                    } catch (Throwable) {
                        AppLogger::event('mqtt', 'mqtt_event_time_invalid', [
                            'topic' => $incomingTopic,
                            'event_time' => $eventTimeRaw,
                        ], 'notice');
                    }
                }

                $deliveryLatency = $scannerSentAt ? $mqttReceivedAt->diffInMilliseconds($scannerSentAt, false) * -1 : null;
                if ($deliveryLatency !== null && $deliveryLatency > $latWarn) {
                    AppLogger::event('mqtt', 'mqtt_latency_warning', [
                        'mqtt_delivery_latency_ms' => $deliveryLatency,
                        'topic' => $incomingTopic,
                        'scanner_sent_at' => $eventTimeIso,
                        'mqtt_received_at' => $mqttReceivedAt->toIso8601String(),
                    ], 'warning');
                }

                $processingStart = microtime(true);
                $movement = $scanIngestService->ingestScan(
                    deviceKey: (string) ($payload['device_key'] ?? ''),
                    trackerUid: (string) ($payload['tracker_uid'] ?? ''),
                    eventTimeIso: $eventTimeIso,
                    source: 'mqtt_scanner'
                );

                AppLogger::event('mqtt', 'mqtt_message_processed', [
                    'topic' => $incomingTopic,
                    'processing_duration_ms' => (int) ((microtime(true) - $processingStart) * 1000),
                    'total_app_latency_ms' => (int) now()->diffInMilliseconds($mqttReceivedAt),
                    'movement_id' => $movement?->id,
                    'event_time' => $eventTimeIso,
                ], AppLogger::shouldLogDiagnostics('mqtt') ? 'info' : 'debug');

                if ($this->option('once')) {
                    $mqtt->interrupt();
                }
            } catch (Throwable $e) {
                AppLogger::exception('mqtt', 'mqtt_message_failed', $e, ['topic' => $incomingTopic]);
                if ($this->option('debug')) {
                    $this->error('MQTT message failed: '.$e->getMessage());
                }
            }
        }, $qos);

        $this->info('MQTT loop started. Waiting for messages...');
        $mqtt->loop(true);
        return self::SUCCESS;
    }
}
