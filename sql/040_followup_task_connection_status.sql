-- Every follow_up_tasks row is specifically about a LinkedIn connection
-- request, so the generic "Done" status is split into two stages that
-- actually mean something for that workflow: Connection Sent (the request
-- went out -- either auto-marked when the LinkedIn "Profile" link on the
-- task is clicked, or via a manual "Mark connection sent" button for when
-- the automatic click tracking doesn't fire) and Connection Accepted
-- (marked once you've confirmed they accepted). Skipped is unchanged.
--
-- Two-step ALTER since MySQL/MariaDB rejects existing rows holding a value
-- ('done') that's no longer in a MODIFY COLUMN's new ENUM list: first widen
-- the set (keeping 'done' valid), migrate the data, then narrow it.

ALTER TABLE follow_up_tasks
    MODIFY COLUMN status ENUM('pending', 'in_progress', 'done', 'connection_sent', 'connection_accepted', 'skipped') NOT NULL DEFAULT 'pending';

UPDATE follow_up_tasks SET status = 'connection_accepted' WHERE status = 'done';

ALTER TABLE follow_up_tasks
    MODIFY COLUMN status ENUM('pending', 'in_progress', 'connection_sent', 'connection_accepted', 'skipped') NOT NULL DEFAULT 'pending';
