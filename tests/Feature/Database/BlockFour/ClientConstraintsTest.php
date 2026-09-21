<?php

namespace Tests\Feature\Database\BlockFour;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesBlockFourRecords;
use Tests\TestCase;

class ClientConstraintsTest extends TestCase
{
    use CreatesBlockFourRecords;
    use RefreshDatabase;

    public function test_client_can_be_created_with_minimum_data_and_defaults(): void
    {
        $id = $this->createClient();
        $client = DB::table('clientes')->where('id', $id)->first();

        $this->assertNotNull($client);
        $this->assertSame($id, $client->id);
        $this->assertSame('Cliente de prueba', $client->nombre);
        $this->assertNull($client->telefono_cifrado);
        $this->assertNull($client->telefono_busqueda_hash);
        $this->assertNull($client->correo);
        $this->assertNull($client->observacion);
        $this->assertTrue($client->activo);
        $this->assertNotNull($client->created_at);
        $this->assertNotNull($client->updated_at);
    }

    public function test_duplicate_client_names_are_allowed(): void
    {
        $firstId = $this->createClient();
        $secondId = $this->createClient();

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, DB::table('clientes')->where('nombre', 'Cliente de prueba')->count());
    }

    #[DataProvider('invalidClientNames')]
    public function test_invalid_client_name_is_rejected(string $name): void
    {
        $this->expectException(QueryException::class);
        $this->createClient(['nombre' => $name]);
    }

    public static function invalidClientNames(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' Cliente'],
            'trailing space' => ['Cliente '],
        ];
    }

    #[DataProvider('approvedPhonePairs')]
    public function test_approved_phone_pair_is_accepted(?string $ciphertext, ?string $hash): void
    {
        $id = $this->createClient([
            'telefono_cifrado' => $ciphertext,
            'telefono_busqueda_hash' => $hash,
        ]);
        $client = DB::table('clientes')->where('id', $id)->first();

        $this->assertNotNull($client);
        $this->assertSame($ciphertext, $client->telefono_cifrado);
        $this->assertSame($hash, $client->telefono_busqueda_hash);
    }

    public static function approvedPhonePairs(): array
    {
        return [
            'both null' => [null, null],
            'opaque synthetic payload' => ['cipher::synthetic/+==payload', str_repeat('a', 64)],
            'opaque payload with exterior spaces' => ['  cipher::synthetic/+== payload  ', str_repeat('b', 64)],
        ];
    }

    #[DataProvider('inconsistentPhonePairs')]
    public function test_inconsistent_phone_pair_is_rejected(?string $ciphertext, ?string $hash): void
    {
        $this->expectException(QueryException::class);
        $this->createClient([
            'telefono_cifrado' => $ciphertext,
            'telefono_busqueda_hash' => $hash,
        ]);
    }

    public static function inconsistentPhonePairs(): array
    {
        return [
            'ciphertext without hash' => ['cipher::synthetic', null],
            'hash without ciphertext' => [null, str_repeat('a', 64)],
        ];
    }

    public function test_empty_ciphertext_is_rejected(): void
    {
        $this->expectException(QueryException::class);
        $this->createClient([
            'telefono_cifrado' => '',
            'telefono_busqueda_hash' => str_repeat('a', 64),
        ]);
    }

    #[DataProvider('invalidPhoneSearchHashes')]
    public function test_invalid_phone_search_hash_is_rejected(string $hash): void
    {
        $this->expectException(QueryException::class);
        $this->createClient([
            'telefono_cifrado' => 'cipher::synthetic',
            'telefono_busqueda_hash' => $hash,
        ]);
    }

    public static function invalidPhoneSearchHashes(): array
    {
        return [
            '63 characters' => [str_repeat('a', 63)],
            '65 characters' => [str_repeat('a', 65)],
            'uppercase hexadecimal' => [str_repeat('A', 64)],
            'non hexadecimal character' => [str_repeat('a', 63).'g'],
            'empty' => [''],
        ];
    }

    public function test_duplicate_phone_search_hash_is_allowed(): void
    {
        $ciphertext = 'cipher::shared-synthetic-payload';
        $hash = str_repeat('c', 64);
        $firstId = $this->createClient([
            'nombre' => 'Cliente uno',
            'telefono_cifrado' => $ciphertext,
            'telefono_busqueda_hash' => $hash,
        ]);
        $secondId = $this->createClient([
            'nombre' => 'Cliente dos',
            'telefono_cifrado' => $ciphertext,
            'telefono_busqueda_hash' => $hash,
        ]);

        $this->assertNotSame($firstId, $secondId);
        $this->assertSame(2, DB::table('clientes')->where('telefono_busqueda_hash', $hash)->count());
    }

    #[DataProvider('approvedClientEmails')]
    public function test_approved_client_email_is_preserved(?string $email): void
    {
        $id = $this->createClient(['correo' => $email]);

        $this->assertSame($email, DB::table('clientes')->where('id', $id)->value('correo'));
    }

    public static function approvedClientEmails(): array
    {
        return [
            'null' => [null],
            'nonempty text' => ['cliente@example.cl'],
        ];
    }

    public function test_duplicate_client_emails_are_allowed(): void
    {
        $email = 'compartido@example.cl';
        $this->createClient(['nombre' => 'Cliente uno', 'correo' => $email]);
        $this->createClient(['nombre' => 'Cliente dos', 'correo' => $email]);

        $this->assertSame(2, DB::table('clientes')->where('correo', $email)->count());
    }

    #[DataProvider('invalidClientEmails')]
    public function test_invalid_client_email_is_rejected(string $email): void
    {
        $this->expectException(QueryException::class);
        $this->createClient(['correo' => $email]);
    }

    public static function invalidClientEmails(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' cliente@example.cl'],
            'trailing space' => ['cliente@example.cl '],
        ];
    }

    #[DataProvider('approvedClientObservations')]
    public function test_approved_client_observation_is_preserved(?string $observation): void
    {
        $id = $this->createClient(['observacion' => $observation]);

        $this->assertSame($observation, DB::table('clientes')->where('id', $id)->value('observacion'));
    }

    public static function approvedClientObservations(): array
    {
        return [
            'null' => [null],
            'nonempty text' => ['Retira pedido por la tarde'],
        ];
    }

    #[DataProvider('invalidClientObservations')]
    public function test_invalid_client_observation_is_rejected(string $observation): void
    {
        $this->expectException(QueryException::class);
        $this->createClient(['observacion' => $observation]);
    }

    public static function invalidClientObservations(): array
    {
        return [
            'empty' => [''],
            'leading space' => [' Observacion'],
            'trailing space' => ['Observacion '],
        ];
    }
}
