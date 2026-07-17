-- Admin-configurable: which bounce types actually trigger the global,
-- account-wide domain suppression (blocking every persona at that
-- domain from being added to any campaign) vs. just recording that one
-- send bounced. Seeded with every bounce-type value used anywhere in the
-- app today (WaveAssigner::BOUNCE_TYPES and DELIVERY_STATUS_BOUNCE_VALUES),
-- all defaulting to "suppresses" so existing behavior is unchanged until
-- an admin explicitly opts a type out (e.g. a soft bounce that shouldn't
-- nuke the whole account). See WaveAssigner::bounceTypeSuppresses().
CREATE TABLE bounce_type_suppression_settings (
    bounce_type  VARCHAR(50) NOT NULL PRIMARY KEY,
    suppresses   TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO bounce_type_suppression_settings (bounce_type, suppresses) VALUES
    ('Hard Bounce', 1),
    ('Soft Bounce', 1),
    ('Spam Complaint', 1),
    ('Invalid Address', 1),
    ('Other', 1),
    ('Bounced', 1),
    ('All Bounced', 1),
    ('Hard Bounced', 1),
    ('Soft Bounced', 1),
    ('Block Bounced', 1);
