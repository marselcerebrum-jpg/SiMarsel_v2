<?php

namespace App\Support;

/**
 * The funnel and source snapshot group of one rekap per kode promo row, as an opaque URL-safe token, so the
 * detail opened from that row shows exactly the usages counted in it.
 */
final class PromoCodeGroup
{
    /**
     * @param  array{funnel_id: int|null, funnel_name: string|null, source_id: int|null, source_name: string|null}  $group
     */
    public static function encode(array $group): string
    {
        $json = json_encode([$group['funnel_id'], $group['funnel_name'], $group['source_id'], $group['source_name']]);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{funnel_id: int|null, funnel_name: string|null, source_id: int|null, source_name: string|null}|null Null when the token is invalid.
     */
    public static function decode(string $token): ?array
    {
        $json = base64_decode(strtr($token, '-_', '+/'), true);
        $values = $json === false ? null : json_decode($json, true);

        if (! is_array($values) || ! array_is_list($values) || count($values) !== 4) {
            return null;
        }

        [$funnelId, $funnelName, $sourceId, $sourceName] = $values;

        foreach ([$funnelId, $sourceId] as $id) {
            if ($id !== null && ! is_int($id)) {
                return null;
            }
        }

        foreach ([$funnelName, $sourceName] as $name) {
            if ($name !== null && ! is_string($name)) {
                return null;
            }
        }

        return ['funnel_id' => $funnelId, 'funnel_name' => $funnelName, 'source_id' => $sourceId, 'source_name' => $sourceName];
    }
}
