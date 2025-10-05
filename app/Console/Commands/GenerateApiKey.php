<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\ApiKey;
use Carbon\Carbon;

class GenerateApiKey extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'apikey:generate 
                            {email : Email del usuario propietario}
                            {name : Nombre descriptivo de la API Key}
                            {--permissions=* : Permisos separados por coma (detecciones,imagenes)}
                            {--ips= : IPs permitidas separadas por coma}
                            {--expires= : Días hasta expiración (opcional)}';

    /**
     * The console command description.
     */
    protected $description = 'Generar una nueva API Key para un usuario';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $name = $this->argument('name');

        // Buscar usuario
        $user = User::where('email', $email)->first();

        if (!$user) {
            $this->error("Usuario no encontrado: {$email}");
            $this->line('');
            $this->info('Usuarios disponibles:');
            User::all()->each(function ($u) {
                $this->line("  - {$u->email} ({$u->name})");
            });
            return 1;
        }

        // Procesar permisos
        $permissions = $this->option('permissions');
        if (!empty($permissions)) {
            $permissions = array_filter($permissions);
        } else {
            $permissions = null;
        }

        // Procesar IPs
        $ipWhitelist = $this->option('ips');

        // Procesar expiración
        $expiresAt = null;
        if ($this->option('expires')) {
            $days = (int) $this->option('expires');
            $expiresAt = Carbon::now()->addDays($days);
        }

        // Confirmar creación
        $this->line('');
        $this->info('Detalles de la API Key:');
        $this->line("Usuario: {$user->name} ({$user->email})");
        $this->line("Nombre: {$name}");
        
        if ($permissions) {
            $this->line("Permisos: " . implode(', ', $permissions));
        } else {
            $this->line("Permisos: Todos (sin restricciones)");
        }
        
        if ($ipWhitelist) {
            $this->line("IPs permitidas: {$ipWhitelist}");
        } else {
            $this->line("IPs permitidas: Todas");
        }
        
        if ($expiresAt) {
            $this->line("Expira: {$expiresAt->format('Y-m-d H:i:s')} ({$this->option('expires')} días)");
        } else {
            $this->line("Expira: Nunca");
        }
        
        $this->line('');

        if (!$this->confirm('Generar esta API Key?', true)) {
            $this->warn('Operación cancelada');
            return 0;
        }

        // Generar API Key
        try {
            $key = ApiKey::generate(
                name: $name,
                userId: $user->id,
                permissions: $permissions,
                ipWhitelist: $ipWhitelist,
                expiresAt: $expiresAt
            );

            $this->line('');
            $this->info('API Key generada exitosamente!');
            $this->line('');
            $this->line('========================================');
            $this->line('');
            $this->warn('API KEY:');
            $this->line('');
            $this->line("  {$key}");
            $this->line('');
            $this->line('========================================');
            $this->line('');
            $this->error('IMPORTANTE:');
            $this->warn('   - Guarda esta key de forma segura');
            $this->warn('   - NO se volverá a mostrar');
            $this->warn('   - Compártela solo con el destinatario autorizado');
            $this->line('');
            $this->info('Uso:');
            $this->line('   curl -H "X-API-Key: ' . $key . '" \\');
            $this->line('        https://apifotomulta.appssvp.com/api/detecciones');
            $this->line('');

            return 0;

        } catch (\Exception $e) {
            $this->error("Error al generar API Key: {$e->getMessage()}");
            return 1;
        }
    }
}