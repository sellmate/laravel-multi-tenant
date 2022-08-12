<?php

namespace Sellmate\Laravel\MultiTenant\Commands;

use Exception;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Sellmate\Laravel\MultiTenant\DatabaseManager;

/**
 * EnvCheck
 */
trait EnvCheck
{
    protected DatabaseManager $manager;

    protected function checkEnv($database = null)
    {
        $database = $database ?? $this->option('database');

        $checkPassword = true;

        if (!$database) {
            if ($this->option('tenant')) {
                $database = $this->manager->tenantConnectionName;
                $checkPassword = false;
            } else {
                $database = $this->manager->systemConnectionName;
            }
        }

        $this->checkDatabase($database, $checkPassword);
    }

    protected function checkDatabase($database, $checkPassword = true)
    {
        $config = Config::get('database.connections.' . $database);

        $prodEnv = Config::get('app.env') === 'production';
        try {
            if ($prodEnv) {
                $this->warn('운영환경에서 명령이 수행됩니다.');
                if ($checkPassword) {
                    $password = $this->secret($database . ' connection의 접속계정 비밀번호를 입력하세요');
                    if ($config['password'] !== $password) {
                        throw new Exception('비밀번호가 일치하지 않습니다', 1);
                    }
                } else {
                    $password = $this->secret('관리 비밀번호를 입력하세요');
                    if (!Hash::check($password, '$2y$10$kqbNcv/ah1xq4ULhJ0CApOAWinL8dbVGkU28j7IvEsYMbeoeqGX2a')) {
                        throw new Exception('비밀번호가 일치하지 않습니다', 1);
                    }
                }
            } elseif (Str::startsWith($config['host'], '10.1.')) {
                throw new Exception('개발 환경에서 운영DB에 대한 작업을 수행할 수 없습니다!', 2);
            }
        } catch (\Throwable $th) {
            $this->error($th->getMessage());
            $this->warn('명령이 취소됩니다.');
            exit;
        }
        $this->info('수행 환경이 검증되었습니다. 명령을 수행합니다.');
    }
}
