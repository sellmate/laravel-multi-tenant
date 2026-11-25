<?php

namespace Sellmate\Laravel\MultiTenant\Commands;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Sellmate\Laravel\MultiTenant\DatabaseManager;
use Sellmate\Laravel\MultiTenant\Exceptions\TenantCommandException;

/**
 * EnvCheck
 */
trait EnvCheck
{
    protected DatabaseManager $manager;

    protected function checkEnv($database = null, $secret = null)
    {
        $database = $database ?? $this->option('database');

        $checkPassword = !$this->option('tenant');

        if (!$database) {
            if ($this->option('tenant')) {
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
                if ($secret) {
                    $password = $secret;
                } else {
                    $password = $this->secret('관리 비밀번호를 입력하세요');
                }
                if (!Hash::check($password, '$2y$10$abpUN4fpMdxHNF/7D60H2uReqJOi4s6vHsbA2mLUplGqtsAcxnstC')) {
                    throw new TenantCommandException('비밀번호가 일치하지 않습니다. 명령이 취소됩니다.', 1);
                }
                Config::set('migration_admin_authenticated', 1);
            }
            // }
        } elseif (!in_array($config['host'] ?? null, config('multitenancy.dev-host-list', []))) {
            throw new TenantCommandException('개발 환경에서 운영DB에 대한 작업을 수행할 수 없습니다! 명령이 취소됩니다.', 2);
        }
        $this->info('수행 환경이 검증되었습니다. 명령을 수행합니다.');
    }
}
