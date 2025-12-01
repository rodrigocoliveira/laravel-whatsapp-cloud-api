<?php

declare(strict_types=1);

namespace Multek\LaravelWhatsAppCloud\Console\Commands;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'whatsapp:install
                            {--force : Overwrite existing files}';

    protected $description = 'Install the WhatsApp Cloud API package';

    public function handle(): int
    {
        $this->info('Installing WhatsApp Cloud API package...');
        $this->newLine();

        // Publish config
        $this->publishConfig();

        // Publish migrations
        $this->publishMigrations();

        // Run migrations prompt
        $this->runMigrations();

        $this->newLine();
        $this->info('WhatsApp Cloud API package installed successfully!');
        $this->newLine();
        $this->displayNextSteps();

        return self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        $this->components->task('Publishing configuration', function () {
            $params = ['--provider' => 'Multek\LaravelWhatsAppCloud\WhatsAppServiceProvider', '--tag' => 'whatsapp-config'];

            if ($this->option('force')) {
                $params['--force'] = true;
            }

            $this->callSilently('vendor:publish', $params);
        });
    }

    protected function publishMigrations(): void
    {
        $this->components->task('Publishing migrations', function () {
            $params = ['--provider' => 'Multek\LaravelWhatsAppCloud\WhatsAppServiceProvider', '--tag' => 'whatsapp-migrations'];

            if ($this->option('force')) {
                $params['--force'] = true;
            }

            $this->callSilently('vendor:publish', $params);
        });
    }

    protected function runMigrations(): void
    {
        if ($this->confirm('Would you like to run the migrations now?', true)) {
            $this->components->task('Running migrations', function () {
                $this->callSilently('migrate');
            });
        }
    }

    protected function displayNextSteps(): void
    {
        $this->components->info('Next steps:');
        $this->newLine();

        $this->line('  1. Add your WhatsApp credentials to <comment>.env</comment>:');
        $this->newLine();
        $this->line('     <comment>WHATSAPP_ACCESS_TOKEN=your_meta_access_token</comment>');
        $this->line('     <comment>WHATSAPP_WEBHOOK_VERIFY_TOKEN=your_verify_token</comment>');
        $this->line('     <comment>WHATSAPP_APP_SECRET=your_app_secret</comment>');
        $this->newLine();

        $this->line('  2. Create a phone in your database:');
        $this->newLine();
        $this->line('     <comment>WhatsAppPhone::create([</comment>');
        $this->line("         <comment>'key' => 'support',</comment>");
        $this->line("         <comment>'phone_id' => 'your_phone_number_id',</comment>");
        $this->line("         <comment>'phone_number' => '+5511999999999',</comment>");
        $this->line("         <comment>'business_account_id' => 'your_waba_id',</comment>");
        $this->line("         <comment>'handler' => App\\WhatsApp\\Handlers\\SupportHandler::class,</comment>");
        $this->line('     <comment>]);</comment>');
        $this->newLine();

        $this->line('  3. Configure the webhook in Meta Business Suite:');
        $this->line('     URL: <comment>'.url(config('whatsapp.webhook.path', 'webhooks/whatsapp')).'</comment>');
        $this->newLine();

        $this->line('  4. Create a message handler implementing <comment>MessageHandlerInterface</comment>');
        $this->newLine();

        $this->components->info('Documentation: https://github.com/multek/laravel-whatsapp-cloud');
    }
}
