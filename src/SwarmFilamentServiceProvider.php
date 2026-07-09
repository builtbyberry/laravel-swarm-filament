<?php

declare(strict_types=1);

namespace BuiltByBerry\LaravelSwarmFilament;

use BuiltByBerry\LaravelSwarmFilament\Support\RunSummaryMemo;
use Filament\Support\Assets\Asset;
use Filament\Support\Facades\FilamentAsset;
use Filament\Support\Facades\FilamentIcon;
use Spatie\LaravelPackageTools\Commands\InstallCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

/**
 * Auto-discovered package provider (a Spatie {@see PackageServiceProvider}, the
 * Filament plugin convention). It wires package-level assets — config, views,
 * translations, Filament assets/icons — but does NOT register the observability
 * surfaces into a panel; that is {@see SwarmFilamentPlugin}'s job, added to a
 * host panel via `->plugin(SwarmFilamentPlugin::make())`.
 *
 * The panel resources read persisted swarm data exclusively through the public
 * read-only contracts shipped in laravel-swarm v0.19 — `InspectsDurableRuns`,
 * `ReadableRunHistoryStore`, and `ReadableAuditOutbox` — which display-decrypt
 * per row (honoring `swarm.persistence.decrypt_failure_policy`) and never mutate
 * state. This package never touches the `@internal` `SwarmPersistenceCipher` or
 * the strict-operational reads.
 */
class SwarmFilamentServiceProvider extends PackageServiceProvider
{
    public static string $name = 'laravel-swarm-filament';

    public static string $viewNamespace = 'swarm-filament';

    public function configurePackage(Package $package): void
    {
        $package->name(static::$name)
            ->hasInstallCommand(function (InstallCommand $command): void {
                $command
                    ->publishConfigFile()
                    ->askToStarRepoOnGitHub('builtbyberry/laravel-swarm-filament');
            });

        $configFileName = $package->shortName();

        if (file_exists($package->basePath("/../config/{$configFileName}.php"))) {
            $package->hasConfigFile();
        }

        if (file_exists($package->basePath('/../resources/lang'))) {
            $package->hasTranslations();
        }

        if (file_exists($package->basePath('/../resources/views'))) {
            $package->hasViews(static::$viewNamespace);
        }
    }

    public function packageRegistered(): void
    {
        // Request-scoped, NOT a singleton: the runs-index summary memo must be
        // flushed at every request boundary. Laravel/Octane clears `scoped()`
        // instances on `RequestReceived` via `forgetScopedInstances()`, so the
        // memo never serves a value produced in an earlier request and never
        // grows unbounded across a worker's life (audit F2).
        $this->app->scoped(RunSummaryMemo::class);
    }

    public function packageBooted(): void
    {
        FilamentAsset::register($this->getAssets(), $this->getAssetPackageName());

        FilamentIcon::register($this->getIcons());
    }

    protected function getAssetPackageName(): ?string
    {
        return 'builtbyberry/laravel-swarm-filament';
    }

    /**
     * @return array<Asset>
     */
    protected function getAssets(): array
    {
        return [];
    }

    /**
     * @return array<string, string>
     */
    protected function getIcons(): array
    {
        return [];
    }
}
