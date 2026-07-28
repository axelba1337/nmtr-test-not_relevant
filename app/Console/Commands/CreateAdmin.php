<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class CreateAdmin extends Command
{
    protected $signature = 'admin:create
                            {--name= : Nama admin}
                            {--email= : Alamat email admin}';

    protected $description = 'Create an administrator account interactively';

    public function handle(): int
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Nama')));
        $email = Str::lower(trim(
            (string) ($this->option('email') ?: $this->ask('Email')),
        ));
        $password = (string) $this->secret(
            'Password (minimal 12 karakter)',
        );
        $passwordConfirmation = (string) $this->secret(
            'Konfirmasi password',
        );

        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'password_confirmation' => $passwordConfirmation,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email',
            ],
            'password' => [
                'required',
                'string',
                'min:12',
                'confirmed',
            ],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'is_admin' => true,
        ]);

        $this->info('Akun admin berhasil dibuat.');

        return self::SUCCESS;
    }
}
