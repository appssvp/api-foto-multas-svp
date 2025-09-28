<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class ProcessImageDownload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 3;
    public $backoff = [60, 120, 300]; // 1min, 2min, 5min

    protected $imageUrl;

    public function __construct(string $imageUrl)
    {
        $this->imageUrl = $imageUrl;
        $this->onQueue('images');
    }

    public function handle()
    {
        $startTime = microtime(true);
        
        try {
            $cacheKey = 'fotomulta_image_' . md5($this->imageUrl);
            
            // Verificar si ya está en cache
            if (Cache::has($cacheKey)) {
                Log::channel('fotomultas')->debug('Image already cached, skipping', [
                    'image_url' => $this->imageUrl,
                    'cache_key' => $cacheKey
                ]);
                return;
            }

            Log::channel('fotomultas')->info('Starting background image download', [
                'image_url' => $this->imageUrl,
                'job_id' => $this->job->getJobId(),
                'attempt' => $this->attempts()
            ]);

            // Extraer ticketId y fileName de la URL
            // Formato: recordId/radarId/ticketId/filename
            if (!preg_match('/^[a-f0-9]{32}\/\d{5}\/(\d+)\/([a-zA-Z0-9._-]+\.(jpg|jpeg|png|gif|bmp))$/i', $this->imageUrl, $matches)) {
                throw new Exception('Invalid image URL format: ' . $this->imageUrl);
            }

            $ticketId = $matches[1];
            $fileName = $matches[2];

            // Descargar usando la API de streetsoncloud
            $imageData = $this->downloadImageFromAPI($ticketId, $fileName);

            if ($imageData) {
                // Cache por 6 horas
                Cache::put($cacheKey, base64_encode($imageData), 21600);
                
                $endTime = microtime(true);
                $processingTime = round(($endTime - $startTime) * 1000, 2);
                
                Log::channel('fotomultas')->info('Image cached successfully in background', [
                    'image_url' => $this->imageUrl,
                    'cache_key' => $cacheKey,
                    'image_size_kb' => round(strlen($imageData) / 1024, 2),
                    'processing_time_ms' => $processingTime,
                    'job_id' => $this->job->getJobId(),
                    'attempt' => $this->attempts()
                ]);
            } else {
                throw new Exception('Failed to download image data');
            }
            
        } catch (Exception $e) {
            $endTime = microtime(true);
            $processingTime = round(($endTime - $startTime) * 1000, 2);
            
            Log::channel('fotomultas')->error('Background image download failed', [
                'image_url' => $this->imageUrl,
                'error' => $e->getMessage(),
                'processing_time_ms' => $processingTime,
                'job_id' => $this->job->getJobId() ?? 'unknown',
                'attempt' => $this->attempts(),
                'max_tries' => $this->tries
            ]);
            
            // Reintenta hasta 3 veces con backoff exponencial
            if ($this->attempts() < $this->tries) {
                $delay = $this->backoff[$this->attempts() - 1] ?? 300;
                Log::channel('fotomultas')->info('Retrying image download', [
                    'image_url' => $this->imageUrl,
                    'attempt' => $this->attempts(),
                    'next_attempt_in_seconds' => $delay
                ]);
                $this->release($delay);
            } else {
                Log::channel('fotomultas')->error('Image download failed permanently', [
                    'image_url' => $this->imageUrl,
                    'final_attempt' => $this->attempts(),
                    'error' => $e->getMessage()
                ]);
                $this->fail($e);
            }
        }
    }

    /**
     * Descargar imagen desde la API de streetsoncloud
     */
    private function downloadImageFromAPI($ticketId, $fileName)
    {
        $apiKey = config('services.streetsoncloud.api_key');
        $baseUrl = config('services.streetsoncloud.api_url');
        
        if (!$apiKey || !$baseUrl) {
            throw new Exception('API configuration missing');
        }
        
        $metaUrl = "{$baseUrl}/ticket-image/{$ticketId}/{$fileName}";

        try {
            // 1) Obtener la URL real de la imagen
            $metaResponse = Http::withHeaders([
                'x-api-key' => $apiKey,
                'X-Requested-With' => 'XMLHttpRequest',
            ])->timeout(30)->get($metaUrl);

            if ($metaResponse->failed()) {
                throw new Exception('Failed to get image metadata: HTTP ' . $metaResponse->status());
            }

            $imageUrl = data_get($metaResponse->json(), 'imageUrl');
            if (!$imageUrl) {
                throw new Exception('Image URL not found in metadata response');
            }

            Log::channel('fotomultas')->debug('Got image URL from metadata', [
                'ticket_id' => $ticketId,
                'filename' => $fileName,
                'image_url' => $imageUrl
            ]);

            // 2) Descargar imagen binaria
            $imageResponse = Http::timeout(60)->get($imageUrl);
            
            if ($imageResponse->failed()) {
                throw new Exception('Failed to download image: HTTP ' . $imageResponse->status());
            }

            $contentType = $imageResponse->header('Content-Type', '');
            if (!str_starts_with($contentType, 'image/')) {
                throw new Exception('Response is not an image, got: ' . $contentType);
            }

            return $imageResponse->body();

        } catch (Exception $e) {
            Log::channel('fotomultas')->error('API image download error', [
                'ticket_id' => $ticketId,
                'filename' => $fileName,
                'error' => $e->getMessage(),
                'meta_url' => $metaUrl
            ]);
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(Exception $exception)
    {
        Log::channel('fotomultas')->error('ProcessImageDownload job failed permanently', [
            'image_url' => $this->imageUrl,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts()
        ]);
    }
}