<?php

declare(strict_types=1);

namespace App\Support\Helpers;

use Illuminate\Support\ServiceProvider;

/**
 * Discovers what the domains under app/Domain provide: service providers and
 * migrations. Adding a domain folder is enough to wire it in.
 */
final class DomainHelpers
{
    public static function domainsPath(): string
    {
        return base_path('app/Domain');
    }

    public static function domainPath(string $domain): string
    {
        return self::domainsPath().'/'.$domain;
    }

    /**
     * @return list<string> e.g. ["Billing", "Generation", ...]
     */
    public static function domains(): array
    {
        $paths = glob(self::domainsPath().'/*', GLOB_ONLYDIR) ?: [];

        return array_map(basename(...), $paths);
    }

    /**
     * Every `app/Domain/{Domain}/Providers/*ServiceProvider.php`.
     *
     * @return list<class-string<ServiceProvider>>
     */
    public static function providers(): array
    {
        $providers = [];

        foreach (self::domains() as $domain) {
            foreach (glob(self::domainPath($domain).'/Providers/*ServiceProvider.php') ?: [] as $file) {
                $class = "App\\Domain\\{$domain}\\Providers\\".basename($file, '.php');

                if (is_subclass_of($class, ServiceProvider::class)) {
                    $providers[] = $class;
                }
            }
        }

        return $providers;
    }

    /**
     * Every `app/Domain/{Domain}/Database/Migrations` folder.
     *
     * @return list<string>
     */
    public static function migrationPaths(): array
    {
        return array_values(array_filter(
            array_map(fn (string $domain): string => self::domainPath($domain).'/Database/Migrations', self::domains()),
            is_dir(...),
        ));
    }
}
