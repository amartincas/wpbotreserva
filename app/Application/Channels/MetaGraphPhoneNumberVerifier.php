<?php

namespace App\Application\Channels;

use App\Application\Contracts\MetaPhoneNumberVerifierInterface;
use App\Application\Notifications\MetaWhatsAppClient;
use App\Domain\Tenancy\Channel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * B9 — verificación con la Graph API de Meta, misma versión que usa
 * MetaWhatsAppClient para enviar:
 *
 *  1. GET /{phone_number_id}?fields=id,display_phone_number,verified_name
 *     con el token del Channel: el número existe, el token lo puede leer, y
 *     display_phone_number coincide (solo dígitos) con el número cargado.
 *  2. GET /{waba_id}/phone_numbers?fields=id: el phone_number_id pertenece
 *     a la WABA cargada.
 *
 * El token viaja solo en el header Authorization — nunca en la URL, en
 * $reason ni en ningún log. Lo que Meta devuelve en un error se reduce a su
 * code/message, nunca se reenvía el body crudo.
 *
 * No verifica la suscripción de la WABA a la App (subscribed_apps) ni los
 * templates aprobados: requieren datos/pasos que hoy viven fuera de este
 * sistema (ver informe de B9).
 */
class MetaGraphPhoneNumberVerifier implements MetaPhoneNumberVerifierInterface
{
    public function verify(Channel $channel): PhoneNumberVerification
    {
        $token = $channel->credentials['access_token'] ?? null;

        if (! $token || ! $channel->phone_number_id || ! $channel->business_account_id || ! $channel->phone_number) {
            return PhoneNumberVerification::failed('El Channel no tiene credenciales completas (phone_number_id, WABA, número y access token).');
        }

        try {
            $phone = $this->get($token, $channel->phone_number_id, ['fields' => 'id,display_phone_number,verified_name']);

            if ($phone->failed()) {
                return PhoneNumberVerification::failed('Meta rechazó la consulta del phone_number_id: '.$this->describeError($phone));
            }

            if ((string) $phone->json('id') !== $channel->phone_number_id) {
                return PhoneNumberVerification::failed('Meta devolvió un phone_number_id distinto del cargado.');
            }

            $display = (string) $phone->json('display_phone_number');

            if ($this->digits($display) !== $this->digits($channel->phone_number)) {
                return PhoneNumberVerification::failed("El número registrado en Meta ({$display}) no coincide con el cargado ({$channel->phone_number}).");
            }

            $waba = $this->get($token, "{$channel->business_account_id}/phone_numbers", ['fields' => 'id']);

            if ($waba->failed()) {
                return PhoneNumberVerification::failed('Meta rechazó la consulta de la WABA: '.$this->describeError($waba));
            }

            if (! in_array($channel->phone_number_id, array_map('strval', array_column($waba->json('data') ?? [], 'id')), true)) {
                return PhoneNumberVerification::failed('El phone_number_id no pertenece a la WABA cargada.');
            }
        } catch (ConnectionException) {
            return PhoneNumberVerification::failed('No se pudo conectar con la Graph API de Meta. Reintentá en unos minutos.');
        }

        return PhoneNumberVerification::verified($display, $phone->json('verified_name'));
    }

    private function get(string $token, string $path, array $query): Response
    {
        return Http::withToken($token)
            ->timeout(15)
            ->get(sprintf('https://graph.facebook.com/%s/%s', MetaWhatsAppClient::API_VERSION, $path), $query);
    }

    private function describeError(Response $response): string
    {
        $code = $response->json('error.code');
        $message = $response->json('error.message');

        return trim("HTTP {$response->status()}".($code !== null ? " (code {$code})" : '').($message ? " — {$message}" : ''));
    }

    private function digits(string $value): string
    {
        return preg_replace('/\D/', '', $value);
    }
}
