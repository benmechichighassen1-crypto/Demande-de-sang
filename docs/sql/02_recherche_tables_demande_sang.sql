-- ============================================================================
-- 02_recherche_tables_demande_sang.sql  —  LECTURE SEULE (uniquement des SELECT)
-- But : retrouver les tables Clinisys derrière les écrans envoyés en capture :
--   DMI « Demande sanguins », DMI « Panier dépôt fournisseur »,
--   Pharmacie « Bon de dépôt FRS » (BD…) et « Bon de prélèvement FRS » (PR…).
-- Méthode : on cherche des valeurs VISIBLES sur les captures dans toutes les
-- colonnes de toutes les tables, puis des noms de colonnes typiques.
--
-- À lancer dans SSMS, sur la base de test (si elle est une copie récente de la
-- production, les valeurs ci-dessous y existent ; sinon remplacez-les par des
-- valeurs visibles dans Clinisys TEST). Si DMI et Pharmacie sont dans deux bases
-- différentes, lancez le script dans chacune (USE NomBase).
-- Résultat : enregistrez chaque grille en CSV dans
--   D:\Projet\Demandedesang\resultats\02_<n° du bloc>.csv
-- ============================================================================

-- BLOC A — Valeurs texte connues (captures du 23-24/10/2026)
IF OBJECT_ID('tempdb..#valeurs') IS NOT NULL DROP TABLE #valeurs;
CREATE TABLE #valeurs (valeur nvarchar(100), signification nvarchar(200));
-- Valeurs d'exemple anonymisées pour la publication : remplacez-les par des
-- valeurs réelles visibles dans Clinisys TEST avant de lancer le bloc.
INSERT INTO #valeurs VALUES
 (N'BD000001',     N'N° bon de dépôt FRS'),
 (N'BD000002',     N'N° bon de dépôt FRS (autre dossier)'),
 (N'PR000001',     N'N° bon de prélèvement FRS'),
 (N'00000000',     N'N° dossier patient (demande sang + bon BD)'),
 (N'00000000000',  N'Identifiant poche (panier dépôt)'),
 (N'B00000000000', N'Identifiant poche (panier dépôt, autre format)'),
 (N'0000000000000',N'Référence fournisseur d''un bon BD'),
 (N'FR0439',       N'Code fournisseur BANQUE DE SANG'),
 (N'DIAGNOSTIC EXEMPLE', N'Diagnostic d''une demande DMI');

IF OBJECT_ID('tempdb..#trouve') IS NOT NULL DROP TABLE #trouve;
CREATE TABLE #trouve (valeur nvarchar(100), table_trouvee nvarchar(300), colonne sysname, nb int);

DECLARE @v nvarchar(100), @sql nvarchar(max);
DECLARE cv CURSOR LOCAL FAST_FORWARD FOR SELECT valeur FROM #valeurs;
OPEN cv; FETCH NEXT FROM cv INTO @v;
WHILE @@FETCH_STATUS = 0
BEGIN
    SET @sql = N'';
    SELECT @sql = @sql
        + N'INSERT INTO #trouve SELECT @v, N''' + c.TABLE_SCHEMA + N'.' + c.TABLE_NAME + N''', N''' + c.COLUMN_NAME
        + N''', COUNT(*) FROM ' + QUOTENAME(c.TABLE_SCHEMA) + N'.' + QUOTENAME(c.TABLE_NAME) + N' WITH (NOLOCK)'
        + N' WHERE LTRIM(RTRIM(' + QUOTENAME(c.COLUMN_NAME) + N')) = @v HAVING COUNT(*) > 0;' + CHAR(10)
    FROM INFORMATION_SCHEMA.COLUMNS c
    JOIN INFORMATION_SCHEMA.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
    WHERE t.TABLE_TYPE = 'BASE TABLE'
      AND c.DATA_TYPE IN ('varchar','nvarchar','char','nchar')
      AND (c.CHARACTER_MAXIMUM_LENGTH >= LEN(@v) OR c.CHARACTER_MAXIMUM_LENGTH = -1);
    EXEC sp_executesql @sql, N'@v nvarchar(100)', @v = @v;
    FETCH NEXT FROM cv INTO @v;
END
CLOSE cv; DEALLOCATE cv;

SELECT t.valeur, v.signification, t.table_trouvee, t.colonne, t.nb
FROM #trouve t JOIN #valeurs v ON v.valeur = t.valeur
ORDER BY t.table_trouvee, t.valeur;

-- BLOC B — Le n° de dossier et les n° de bon peuvent être stockés en nombre
DECLARE @n bigint;
IF OBJECT_ID('tempdb..#trouve_num') IS NOT NULL DROP TABLE #trouve_num;
CREATE TABLE #trouve_num (valeur bigint, table_trouvee nvarchar(300), colonne sysname, nb int);
DECLARE cn CURSOR LOCAL FAST_FORWARD FOR
    SELECT v FROM (VALUES (10000001), (100001), (100002), (100003), (1000000001)) x(v); -- valeurs anonymisées, à remplacer
OPEN cn; FETCH NEXT FROM cn INTO @n;
WHILE @@FETCH_STATUS = 0
BEGIN
    SET @sql = N'';
    SELECT @sql = @sql
        + N'INSERT INTO #trouve_num SELECT @n, N''' + c.TABLE_SCHEMA + N'.' + c.TABLE_NAME + N''', N''' + c.COLUMN_NAME
        + N''', COUNT(*) FROM ' + QUOTENAME(c.TABLE_SCHEMA) + N'.' + QUOTENAME(c.TABLE_NAME) + N' WITH (NOLOCK)'
        + N' WHERE ' + QUOTENAME(c.COLUMN_NAME) + N' = @n HAVING COUNT(*) > 0;' + CHAR(10)
    FROM INFORMATION_SCHEMA.COLUMNS c
    JOIN INFORMATION_SCHEMA.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
    WHERE t.TABLE_TYPE = 'BASE TABLE'
      AND c.DATA_TYPE IN ('int','bigint','numeric','decimal')
      AND (@n < 2147483647 OR c.DATA_TYPE <> 'int');
    EXEC sp_executesql @sql, N'@n bigint', @n = @n;
    FETCH NEXT FROM cn INTO @n;
END
CLOSE cn; DEALLOCATE cn;
SELECT * FROM #trouve_num ORDER BY table_trouvee, valeur;

-- BLOC C — Tables qui contiennent les libellés « Banque de sang » / « CULOT GLOBULAIRE »
--          (tables de référence : dépôts, articles)
SET @sql = N'';
IF OBJECT_ID('tempdb..#libelles') IS NOT NULL DROP TABLE #libelles;
CREATE TABLE #libelles (libelle nvarchar(100), table_trouvee nvarchar(300), colonne sysname, nb int);
SELECT @sql = @sql
    + N'INSERT INTO #libelles SELECT x.l, N''' + c.TABLE_SCHEMA + N'.' + c.TABLE_NAME + N''', N''' + c.COLUMN_NAME
    + N''', COUNT(*) FROM ' + QUOTENAME(c.TABLE_SCHEMA) + N'.' + QUOTENAME(c.TABLE_NAME) + N' WITH (NOLOCK)'
    + N' CROSS JOIN (VALUES (N''Banque de sang''), (N''CULOT GLOBULAIRE%''), (N''PFC%'')) x(l)'
    + N' WHERE ' + QUOTENAME(c.COLUMN_NAME) + N' LIKE x.l GROUP BY x.l;' + CHAR(10)
FROM INFORMATION_SCHEMA.COLUMNS c
JOIN INFORMATION_SCHEMA.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
WHERE t.TABLE_TYPE = 'BASE TABLE'
  AND c.DATA_TYPE IN ('varchar','nvarchar','char','nchar')
  AND c.CHARACTER_MAXIMUM_LENGTH BETWEEN 10 AND 255;
EXEC sp_executesql @sql;
SELECT * FROM #libelles ORDER BY table_trouvee;

-- BLOC D — Noms de colonnes qui ressemblent aux champs du formulaire DMI « Demande sanguins »
SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE
FROM INFORMATION_SCHEMA.COLUMNS
WHERE    COLUMN_NAME LIKE '%transf%'  OR COLUMN_NAME LIKE '%RAI%'      OR COLUMN_NAME LIKE '%coombs%'
      OR COLUMN_NAME LIKE '%TDC%'     OR COLUMN_NAME LIKE '%CGR%'      OR COLUMN_NAME LIKE '%plaquet%'
      OR COLUMN_NAME LIKE '%plasma%'  OR COLUMN_NAME LIKE '%PFC%'      OR COLUMN_NAME LIKE '%NFS%'
      OR COLUMN_NAME LIKE '%determ%'  OR COLUMN_NAME LIKE '%groupe%'   OR COLUMN_NAME LIKE '%rhesus%'
      OR COLUMN_NAME LIKE '%peremp%'  OR COLUMN_NAME LIKE '%satisf%'   OR COLUMN_NAME LIKE '%identifiant%'
ORDER BY TABLE_NAME, COLUMN_NAME;

-- BLOC E — Une fois les tables trouvées : structure + 20 dernières lignes de chacune
-- (remplacez NomTable ; ne partagez pas de noms de patients hors de la clinique)
-- SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE
--   FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'NomTable' ORDER BY ORDINAL_POSITION;
-- SELECT TOP 20 * FROM dbo.NomTable ORDER BY 1 DESC;
