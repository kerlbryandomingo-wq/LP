-- Default, non-sensitive seed data — a starter workflow template and a
-- business_settings row. The admin account is created separately and
-- interactively by the installer (install/steps/05_admin.php), never
-- seeded with a fixed password here.

INSERT INTO business_settings (id, business_name, tagline, active_theme)
VALUES ('default', 'LP System', 'Your Partner in Printing Needs', '2026')
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO workflow_templates (id, name, active) VALUES
    ('wft_default', 'Standard Printing Workflow', 1)
ON DUPLICATE KEY UPDATE id = id;

INSERT INTO workflow_stages (id, template_id, name, stage_order, is_qc_stage, is_install_stage, is_design_stage) VALUES
    ('wfs_1', 'wft_default', 'Design', 1, 0, 0, 1),
    ('wfs_2', 'wft_default', 'Printing', 2, 0, 0, 0),
    ('wfs_3', 'wft_default', 'Finishing', 3, 0, 0, 0),
    ('wfs_4', 'wft_default', 'Quality Control', 4, 1, 0, 0),
    ('wfs_5', 'wft_default', 'Ready for Release', 5, 0, 0, 0)
ON DUPLICATE KEY UPDATE id = id;
