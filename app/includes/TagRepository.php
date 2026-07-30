<?php

/**
 * Local tag CRUD and lead<->tag attachment. Tags are shared across leads
 * (a small, admin-curated list, same spirit as verticals/services) rather
 * than free text per lead, so pushes to Saleshandy stay consistent and
 * typo-free.
 */
class TagRepository
{
    /** @return array<int,array{id:int,name:string,saleshandy_tag_id:?string}> */
    public static function all(PDO $db, int $companyId): array
    {
        $stmt = $db->prepare('SELECT id, name, saleshandy_tag_id FROM tags WHERE company_id = ? ORDER BY name');
        $stmt->execute([$companyId]);
        return $stmt->fetchAll();
    }

    public static function findOrCreateByName(PDO $db, string $name, int $companyId): int
    {
        $name = trim($name);
        $stmt = $db->prepare('SELECT id FROM tags WHERE name = ? AND company_id = ?');
        $stmt->execute([$name, $companyId]);
        $id = $stmt->fetchColumn();
        if ($id) {
            return (int) $id;
        }
        $db->prepare('INSERT INTO tags (company_id, name) VALUES (?, ?)')->execute([$companyId, $name]);
        return (int) $db->lastInsertId();
    }

    /**
     * Upserts tags fetched from Saleshandy (by saleshandy_tag_id), matching
     * an existing local tag of the same name if one exists rather than
     * creating a duplicate.
     *
     * @param array<int,array{id:string,name:string}> $saleshandyTags
     * @return int number of tags created or updated
     */
    public static function syncFromSaleshandy(PDO $db, array $saleshandyTags, int $companyId): int
    {
        $stmt = $db->prepare(
            'INSERT INTO tags (company_id, name, saleshandy_tag_id) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE saleshandy_tag_id = VALUES(saleshandy_tag_id)'
        );
        $count = 0;
        foreach ($saleshandyTags as $tag) {
            $name = trim((string) ($tag['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $stmt->execute([$companyId, $name, (string) $tag['id']]);
            $count++;
        }
        return $count;
    }

    /** @return array<int,string> tag names for this lead, alphabetical */
    public static function namesForLead(PDO $db, int $leadId): array
    {
        $stmt = $db->prepare(
            'SELECT t.name FROM tags t JOIN lead_tags lt ON lt.tag_id = t.id WHERE lt.lead_id = ? ORDER BY t.name'
        );
        $stmt->execute([$leadId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return array<int,int> tag ids for this lead */
    public static function tagIdsForLead(PDO $db, int $leadId): array
    {
        $stmt = $db->prepare('SELECT tag_id FROM lead_tags WHERE lead_id = ?');
        $stmt->execute([$leadId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Replaces a lead's full tag set with the given tag names, creating
     * any that don't exist locally yet.
     *
     * @param array<int,string> $tagNames
     */
    public static function setTagsForLead(PDO $db, int $leadId, array $tagNames, int $companyId): void
    {
        $tagIds = [];
        foreach ($tagNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $tagIds[] = self::findOrCreateByName($db, $name, $companyId);
        }

        $db->prepare('DELETE FROM lead_tags WHERE lead_id = ?')->execute([$leadId]);
        if ($tagIds) {
            $insert = $db->prepare('INSERT IGNORE INTO lead_tags (lead_id, tag_id) VALUES (?, ?)');
            foreach (array_unique($tagIds) as $tagId) {
                $insert->execute([$leadId, $tagId]);
            }
        }
    }

    /**
     * Adds tag names to a lead without removing any existing tags --
     * used during import, where a default/mapped tag list should
     * accumulate rather than overwrite (a re-imported lead keeps tags
     * assigned by a previous file).
     *
     * @param array<int,string> $tagNames
     */
    public static function addTagsToLead(PDO $db, int $leadId, array $tagNames, int $companyId): void
    {
        $insert = $db->prepare('INSERT IGNORE INTO lead_tags (lead_id, tag_id) VALUES (?, ?)');
        foreach ($tagNames as $name) {
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $insert->execute([$leadId, self::findOrCreateByName($db, $name, $companyId)]);
        }
    }

    /**
     * Adds one tag (by name, created if new) to every lead in the given id
     * list, without touching any lead's existing tags -- the bulk
     * counterpart of addTagsToLead(), for "tag everything matching this
     * filter" on the Dashboard and Add Leads to Campaign screens.
     *
     * @param array<int,int> $leadIds
     * @return int how many leads actually gained the tag (excludes ones
     *   that already had it, since INSERT IGNORE is a no-op there)
     */
    public static function addTagToLeadIds(PDO $db, array $leadIds, string $tagName, int $companyId): int
    {
        $leadIds = array_values(array_unique(array_filter(array_map('intval', $leadIds))));
        $tagName = trim($tagName);
        if (!$leadIds || $tagName === '') {
            return 0;
        }
        $tagId = self::findOrCreateByName($db, $tagName, $companyId);
        $insert = $db->prepare('INSERT IGNORE INTO lead_tags (lead_id, tag_id) VALUES (?, ?)');
        $count = 0;
        foreach ($leadIds as $leadId) {
            $insert->execute([$leadId, $tagId]);
            $count += $insert->rowCount();
        }
        return $count;
    }

    /**
     * Groups lead ids by their tag signature (sorted, comma-joined tag
     * names), for batching a Saleshandy push -- that API applies tags to
     * a whole import call, not per-prospect, so leads with different tag
     * sets must be pushed in separate calls.
     *
     * @param array<int,int> $leadIds
     * @return array<string,array{tags:array<int,string>,lead_ids:array<int,int>}> keyed by signature
     */
    public static function groupLeadsByTagSet(PDO $db, array $leadIds): array
    {
        if (!$leadIds) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($leadIds), '?'));
        $stmt = $db->prepare(
            "SELECT lt.lead_id, t.name FROM lead_tags lt JOIN tags t ON t.id = lt.tag_id
              WHERE lt.lead_id IN ({$placeholders}) ORDER BY t.name"
        );
        $stmt->execute($leadIds);

        $tagsByLead = array_fill_keys($leadIds, []);
        foreach ($stmt as $row) {
            $tagsByLead[(int) $row['lead_id']][] = $row['name'];
        }

        $groups = [];
        foreach ($tagsByLead as $leadId => $tags) {
            $signature = implode(',', $tags);
            if (!isset($groups[$signature])) {
                $groups[$signature] = ['tags' => $tags, 'lead_ids' => []];
            }
            $groups[$signature]['lead_ids'][] = $leadId;
        }
        return $groups;
    }
}
