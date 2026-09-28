<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypts Aadhaar (national_id), PAN (tax_id) and bank account numbers at
 * rest with the application key (AES-256-CBC + MAC via Laravel's encrypter;
 * the Employee model reads them through the `encrypted` cast).
 *
 * IMPORTANT: these values are only recoverable with APP_KEY. Rotate the key
 * only via APP_PREVIOUS_KEYS, never by replacing it.
 *
 * Idempotent: values that already decrypt are left alone, so a re-run (or a
 * partially completed run) never double-encrypts.
 */
return new class extends Migration
{
    private array $columns = ['national_id', 'tax_id', 'bank_account_number'];

    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            foreach ($this->columns as $column) {
                $table->text($column)->nullable()->change();
            }
        });

        $this->transform(function (string $value) {
            try {
                Crypt::decryptString($value);

                return null; // already encrypted
            } catch (DecryptException) {
                return Crypt::encryptString($value);
            }
        });
    }

    public function down(): void
    {
        $this->transform(function (string $value) {
            try {
                return Crypt::decryptString($value);
            } catch (DecryptException) {
                return null; // already plaintext
            }
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('national_id', 100)->nullable()->change();
            $table->string('tax_id', 100)->nullable()->change();
            $table->string('bank_account_number', 100)->nullable()->change();
        });
    }

    /** Applies $fn to every non-empty sensitive value; a null return means "leave as is". */
    private function transform(callable $fn): void
    {
        DB::table('employees')->select(array_merge(['id'], $this->columns))->orderBy('id')
            ->chunkById(200, function ($rows) use ($fn) {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach ($this->columns as $column) {
                        if ($row->{$column} !== null && $row->{$column} !== '') {
                            $new = $fn($row->{$column});
                            if ($new !== null) {
                                $changes[$column] = $new;
                            }
                        }
                    }

                    if ($changes) {
                        DB::table('employees')->where('id', $row->id)->update($changes);
                    }
                }
            });
    }
};
