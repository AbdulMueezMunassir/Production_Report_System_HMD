-- sql/seed_data.sql
-- Seed Devotion Types and Components

INSERT INTO devotion_types (name, slug, type, sort_order) VALUES
('Shirt', 'shirt', 'garment', 1),
('Trouser', 'trouser', 'garment', 2),
('Coat', 'coat', 'garment', 3),
('Shirt MTM', 'shirt_mtm', 'garment', 4),
('Trouser MTM', 'trouser_mtm', 'garment', 5),
('Coat MTM', 'coat_mtm', 'garment', 6),
('Assemble', 'assemble', 'assembly', 7);

-- Shirt Components
INSERT INTO devotion_components (devotion_id, component_name, is_match_out, sort_order) VALUES
(1, 'Front', 0, 1),
(1, 'Back', 0, 2),
(1, 'Sleeve', 0, 3),
(1, 'Collar', 0, 4),
(1, 'Cuff', 0, 5),
(1, 'Match Out', 1, 6);

-- Trouser Components
INSERT INTO devotion_components (devotion_id, component_name, is_match_out, sort_order) VALUES
(2, 'Front', 0, 1),
(2, 'Back', 0, 2),
(2, 'Band', 0, 3),
(2, 'Match Out', 1, 4);

-- Coat Components
INSERT INTO devotion_components (devotion_id, component_name, is_match_out, sort_order) VALUES
(3, 'Front', 0, 1),
(3, 'Back', 0, 2),
(3, 'Sleeve', 0, 3),
(3, 'Collar', 0, 4),
(3, 'Match Out', 1, 5);

-- Shirt MTM Components
INSERT INTO devotion_components (devotion_id, component_name, is_match_out, sort_order) VALUES
(4, 'Front', 0, 1),
(4, 'Back', 0, 2),
(4, 'Sleeve', 0, 3),
(4, 'Collar', 0, 4),
(4, 'Cuff', 0, 5),
(4, 'Match Out', 1, 6);

-- Trouser MTM Components
INSERT INTO devotion_components (devotion_id, component_name, is_match_out, is_dhu, sort_order) VALUES
(5, 'Front', 0, 0, 1),
(5, 'Back', 0, 0, 2),
(5, 'Band', 0, 0, 3),
(5, 'Match Out', 1, 0, 4),
(5, 'DHU', 0, 1, 5);

-- Coat MTM Components
INSERT INTO devotion_components (devotion_id, component_name, is_match_out, is_dhu, sort_order) VALUES
(6, 'Front', 0, 0, 1),
(6, 'Back', 0, 0, 2),
(6, 'Sleeve', 0, 0, 3),
(6, 'Collar', 0, 0, 4),
(6, 'Match Out', 1, 0, 5),
(6, 'DHU', 0, 1, 6);

-- Assemble Components
INSERT INTO devotion_components (devotion_id, component_name, is_match_out, is_dhu, sort_order) VALUES
(7, 'SHIRT', 0, 0, 1),
(7, 'DHU', 0, 1, 2),
(7, 'TROUSER', 0, 0, 3),
(7, 'DHU', 0, 1, 4),
(7, 'COAT', 0, 0, 5),
(7, 'DHU', 0, 1, 6),
(7, 'SHIRT MTM', 0, 0, 7),
(7, 'DHU', 0, 1, 8),
(7, 'TROUSER MTM', 0, 0, 9),
(7, 'DHU', 0, 1, 10),
(7, 'COAT MTM', 0, 0, 11),
(7, 'DHU', 0, 1, 12),
(7, 'Lean total', 0, 0, 13),
(7, 'DHU', 0, 1, 14);