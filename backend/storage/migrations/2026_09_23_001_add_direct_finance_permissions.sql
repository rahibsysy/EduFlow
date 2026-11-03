-- Explicit, per-user finance access. Roles remain the default RBAC mechanism.
CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INT NOT NULL,
    permission_id INT NOT NULL,
    PRIMARY KEY (user_id, permission_id),
    KEY user_permissions_permission_idx (permission_id),
    CONSTRAINT user_permissions_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT user_permissions_permission_fk FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

INSERT IGNORE INTO permissions (code, label) VALUES
('finance.view', 'Consulter les données financières'),
('finance.manage', 'Gérer les opérations financières');

-- Administrators retain their existing school-scoped finance responsibilities.
INSERT IGNORE INTO role_permissions (role, permission_id)
SELECT 'admin', id FROM permissions WHERE code IN ('finance.view', 'finance.manage');
