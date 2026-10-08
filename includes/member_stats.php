<?php
// includes/member_stats.php
// Member statistics for the home page (and anywhere else that needs them).
//
// PEOPLE ARE COUNTED BY MEMBER ID. One Member ID is one person, even when it covers the DCW
// Generic Community plus several clubs. Memberships (one row per club) are counted separately.
//
// Built on MemberModel::memberInfoMap(), which already returns one row per membership with its
// member_id and expires_at. "Active" means the person holds at least one membership whose expiry
// date is still in the future (expires_at is stored in UTC).
//
// Never throws: on any failure it returns ok = false, so a broken statistics query can never
// take the public home page down.

/**
 * @return array ['ok' => bool, 'people' => int, 'active' => int, 'memberships' => int]
 *   people       distinct Member IDs ever issued
 *   active       distinct Member IDs with at least one unexpired membership
 *   memberships  membership rows (an ID in three clubs counts three times here)
 */
function member_stats(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $cache = ['ok' => false, 'people' => 0, 'active' => 0, 'memberships' => 0];

    try {
        require_once __DIR__ . '/../models/MemberModel.php';
        $rows = (new MemberModel())->memberInfoMap();

        $now = time();
        $people = [];
        $active = [];
        $memberships = 0;

        foreach ($rows as $r) {
            $id = trim((string) ($r['member_id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $memberships++;
            $people[$id] = true;

            $expires = !empty($r['expires_at']) ? strtotime((string) $r['expires_at'] . ' UTC') : false;
            if ($expires !== false && $expires > $now) {
                $active[$id] = true;
            }
        }

        $cache = [
            'ok'          => true,
            'people'      => count($people),
            'active'      => count($active),
            'memberships' => $memberships,
        ];
    } catch (Throwable $e) {
        // leave ok = false
    }

    return $cache;
}
