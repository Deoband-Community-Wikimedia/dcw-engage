-- DCW Engage - one-time import of Template:DCW/Members (Meta-Wiki) into the public members list.
-- Run AFTER member_public_directory.sql (that creates the legacy_members table).
-- This is DATA, not schema, so it is deliberately NOT part of database.sql.
-- Run it in a client that uses utf8mb4 (phpMyAdmin does): one username is in Arabic script.
--
-- chapter = '' means "club not known or not one of the four Engage clubs". Those people show in the
-- "Listed before Engage" section without a club.
-- A legacy entry is hidden automatically once the same username is on Engage (listed, struck off or
-- opted out), so nobody appears twice and opt-outs always win.
-- To remove someone: /admin/public-directory (Remove button), or DELETE FROM legacy_members WHERE username = '...';
-- Safe to re-run: INSERT IGNORE and the unique key on (username, chapter).

-- DCW Generic Community (includes Executive Board / Board / Programs rows: CHECK this mapping)
INSERT IGNORE INTO legacy_members (username, chapter, added_at) VALUES
 ('Aafi', 'generic', UTC_TIMESTAMP()),
 ('Alnitak-Alnilam-Mintaka', 'generic', UTC_TIMESTAMP()),
 ('Aqueel23', 'generic', UTC_TIMESTAMP()),
 ('Koshur', 'generic', UTC_TIMESTAMP()),
 ('Muntaqibah', 'generic', UTC_TIMESTAMP()),
 ('Musical starlight', 'generic', UTC_TIMESTAMP()),
 ('Raydann', 'generic', UTC_TIMESTAMP()),
 ('Sammmm23', 'generic', UTC_TIMESTAMP()),
 ('Shadab', 'generic', UTC_TIMESTAMP()),
 ('خوشبو', 'generic', UTC_TIMESTAMP());

-- Wiki Club AMU
INSERT IGNORE INTO legacy_members (username, chapter, added_at) VALUES
 ('AJ12119', 'amu', UTC_TIMESTAMP()),
 ('Brainwavewizard', 'amu', UTC_TIMESTAMP()),
 ('CosmicCodex', 'amu', UTC_TIMESTAMP()),
 ('Durre Shehvaar', 'amu', UTC_TIMESTAMP()),
 ('Ivyselene', 'amu', UTC_TIMESTAMP()),
 ('Khanahmedsam', 'amu', UTC_TIMESTAMP()),
 ('Khanifraqosam', 'amu', UTC_TIMESTAMP()),
 ('ObsidianMasha', 'amu', UTC_TIMESTAMP()),
 ('Shazra Wali', 'amu', UTC_TIMESTAMP()),
 ('Shzshb', 'amu', UTC_TIMESTAMP()),
 ('Sora0007', 'amu', UTC_TIMESTAMP());

-- Wiki Club Jamia
INSERT IGNORE INTO legacy_members (username, chapter, added_at) VALUES
 ('Aaqibj', 'jamia', UTC_TIMESTAMP()),
 ('Axshazxaf', 'jamia', UTC_TIMESTAMP()),
 ('Bloomingbibliophile', 'jamia', UTC_TIMESTAMP()),
 ('DilliKiShaam', 'jamia', UTC_TIMESTAMP()),
 ('Durre Shehvaar', 'jamia', UTC_TIMESTAMP()),
 ('Faismeen', 'jamia', UTC_TIMESTAMP()),
 ('Humaira Ali', 'jamia', UTC_TIMESTAMP()),
 ('Khajb', 'jamia', UTC_TIMESTAMP()),
 ('Mirzaghalibkibegum', 'jamia', UTC_TIMESTAMP()),
 ('Ridday', 'jamia', UTC_TIMESTAMP()),
 ('Sabaaya', 'jamia', UTC_TIMESTAMP()),
 ('Striving tranquil', 'jamia', UTC_TIMESTAMP()),
 ('Tamjeed Ahmed', 'jamia', UTC_TIMESTAMP()),
 ('TheAutumnal', 'jamia', UTC_TIMESTAMP()),
 ('ThePoeticFrame', 'jamia', UTC_TIMESTAMP()),
 ('The social view', 'jamia', UTC_TIMESTAMP());

-- No Engage club on the template (Wiki Club Deoband, Urdu Wikipedia, Commons, Community Building, blank)
INSERT IGNORE INTO legacy_members (username, chapter, added_at) VALUES
 ('Ardbd', '', UTC_TIMESTAMP()),
 ('Ghalib241', '', UTC_TIMESTAMP()),
 ('Habba khatoon509', '', UTC_TIMESTAMP()),
 ('K.amyrah', '', UTC_TIMESTAMP()),
 ('KahkashaaN', '', UTC_TIMESTAMP()),
 ('Khaatir', '', UTC_TIMESTAMP()),
 ('Mahi zahidi', '', UTC_TIMESTAMP()),
 ('Numis Zuhair', '', UTC_TIMESTAMP()),
 ('SafdarQuddus', '', UTC_TIMESTAMP());

-- SELECT COUNT(*) FROM legacy_members;
