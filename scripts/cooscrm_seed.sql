-- Seed initial tree: start projects + subideas
-- Run after schema is created.

-- Root projects
INSERT INTO nodes (parent_id, type, status, title, description, priority, created_by)
VALUES
  (NULL, 'project', 'active', 'coos.eu (Personal CRM / Idea-to-Cash OS)', 'Personal CRM: ideas -> projects -> execution. Dark UI. Login. DB-first. Nightly concepts written into dashboard.', 1, 'james'),
  (NULL, 'project', 'active', 'investortools.de', 'Trading tools + nightly runner + setups/traders + logs. Collect dry-run data + improve strategies.', 2, 'james');

-- Capture IDs
SET @coos_id = (SELECT id FROM nodes WHERE title LIKE 'coos.eu (%' ORDER BY id DESC LIMIT 1);
SET @invest_id = (SELECT id FROM nodes WHERE title = 'investortools.de' ORDER BY id DESC LIMIT 1);

-- coos subideas
INSERT INTO nodes (parent_id, type, status, title, description, priority, created_by)
VALUES
  (@coos_id, 'task', 'active', 'Dark theme (white + gold, hacker-style)', 'Global UI theme: dark background, off-white text, gold accents, monospace meta lines.', 1, 'oliver'),
  (@coos_id, 'task', 'active', 'Login area (single-user)', 'Login/logout, password_hash, session auth, protect all pages.', 1, 'oliver'),
  (@coos_id, 'task', 'active', 'DB schema: nodes + node_notes', 'Tree of ideas/projects with per-node note ledger; notes display as [author] timestamp - text.', 1, 'oliver'),
  (@coos_id, 'task', 'active', 'Nightly job: concepts -> DB', '01:30 job creates 3–5 concepts and writes as nodes + notes for Oliver to triage.', 2, 'james'),
  (@coos_id, 'task', 'active', 'API gateway access via api.coos.eu', 'IP allowlist + token auth; old public gateway port disabled.', 2, 'james');

-- investortools subideas
INSERT INTO nodes (parent_id, type, status, title, description, priority, created_by)
VALUES
  (@invest_id, 'task', 'active', '1-min tick decisions + trade ledger', 'Dry-run decisions for multiple traders; write events + P/L computed from quantity/cost.', 1, 'oliver'),
  (@invest_id, 'task', 'active', 'Trades/Events schema + UI', 'Trades ledger UI + schema scripts (already prototyped in investortools repo).', 2, 'james'),
  (@invest_id, 'task', 'active', 'Improve logs + health status', 'File logs + status widget + progress counts.', 3, 'james');

-- Notes (main "notizzettel" per node)
INSERT INTO node_notes (node_id, author, note)
VALUES
  (@coos_id, 'james', 'Startprojekt coos.eu als persönliches CRM/Idea-to-Cash OS. Fokus: DB-first Tree + Notizzettel je Node. Dark theme (weiß/gold).'),
  (@invest_id, 'james', 'Startprojekt investortools.de. Fokus: Daten sammeln, Cron pipeline, Setups/Trader, Trade-Events Ledger Planung.');
