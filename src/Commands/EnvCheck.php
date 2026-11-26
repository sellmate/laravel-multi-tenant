<?php

namespace Sellmate\Laravel\MultiTenant\Commands;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\ConfigurationUrlParser;
use Illuminate\Support\Facades\Hash;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Sellmate\Laravel\MultiTenant\Exceptions\EnvironmentRefusedException;

/**
 * EnvCheck
 */
trait EnvCheck
{
    protected DatabaseManager $manager;

    protected function checkEnv($database = null, $secret = null)
    {
        $database = $database ?? ($this->hasOption('database') ? $this->option('database') : null);

        if (!$database) {
            if ($this->hasOption('tenant') && $this->option('tenant')) {
                $database = $this->manager->tenantConnectionName;
            } else {
                $database = $this->manager->systemConnectionName;
            }
        }

        $this->checkDatabase($database, $secret);
    }

    protected function checkDatabase($database, $secret)
    {
        $config = Config::get('database.connections.' . $database);

        $prodEnv = Config::get('app.env') === 'production';
        if ($prodEnv) {
            $this->warn('운영환경에서 명령이 수행됩니다.');
            // if ($checkPassword) {
            //     $password = $this->secret($database . ' connection의 접속계정 비밀번호를 입력하세요');
            //     if ($config['password'] !== $password) {
            //         throw new Exception('비밀번호가 일치하지 않습니다', 1);
            //     }
            // } else {
            if (!Config::get('migration_admin_authenticated')) {
                if ($secret !== null && $secret !== '') {
                    $password = $secret;
                } else {
                    $password = $this->secret('관리 비밀번호를 입력하세요');
                }
                if ($password === null || $password === '') {
                    throw new EnvironmentRefusedException('관리 비밀번호가 필요합니다. 대화형으로 실행하세요. 명령이 취소됩니다.', 1);
                }
                if (!Hash::check($password, '$2y$10$abpUN4fpMdxHNF/7D60H2uReqJOi4s6vHsbA2mLUplGqtsAcxnstC')) {
                    throw new EnvironmentRefusedException('비밀번호가 일치하지 않습니다. 명령이 취소됩니다.', 1);
                }
                Config::set('migration_admin_authenticated', 1);
            }
            // }
        } elseif (! $this->isDevelopmentDatabase($config ?? [])) {
            throw new EnvironmentRefusedException('개발 환경에서 운영DB에 대한 작업을 수행할 수 없습니다! 명령이 취소됩니다.', 2);
        }
        $this->info('수행 환경이 검증되었습니다. 명령을 수행합니다.');
    }

    private function isDevelopmentDatabase(array $config): bool
    {
        // Laravel lets `url` override host when connecting, so judge the parsed result.
        $config = (new ConfigurationUrlParser)->parseConfiguration($config);

        // SQLite has no server: the database is a local file or in memory.
        if (($config['driver'] ?? null) === 'sqlite') {
            return true;
        }

        $devHosts = array_map('trim', (array) config('multitenancy.dev-host-list', []));

        return isset($config['host']) && in_array($config['host'], $devHosts, true);
    }
}
