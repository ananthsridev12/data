-- Only Company Name, First Name, Last Name, and Email stay mandatory on
-- import (see LEAD_FIELDS in app/config/constants.php). These columns were
-- still NOT NULL at the schema level from the original, much stricter
-- required-fields list, which broke inserts as soon as a file didn't
-- supply them and no default value was set on the mapping screen.

ALTER TABLE leads
    MODIFY COLUMN category                VARCHAR(150) NULL,
    MODIFY COLUMN products                 TEXT NULL,
    MODIFY COLUMN title                    VARCHAR(255) NULL,
    MODIFY COLUMN company_name_for_emails  VARCHAR(255) NULL,
    MODIFY COLUMN industry                 VARCHAR(150) NULL,
    MODIFY COLUMN person_linkedin_url      VARCHAR(500) NULL,
    MODIFY COLUMN website                  VARCHAR(255) NULL,
    MODIFY COLUMN company_linkedin_url     VARCHAR(500) NULL,
    MODIFY COLUMN company_country          VARCHAR(150) NULL;
