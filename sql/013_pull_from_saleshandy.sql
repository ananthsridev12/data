-- Pulling in leads already enrolled in a running Saleshandy sequence (see
-- SaleshandyClient::pullNewProspects()) only gives us email + name, no
-- company -- so Company Name joins the earlier required-fields relaxation
-- (sql/010_relax_required_fields.sql) and becomes optional too.

ALTER TABLE leads
    MODIFY COLUMN na_company_name VARCHAR(255) NULL;
