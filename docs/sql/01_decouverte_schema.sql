-- ============================================================================
-- 01_decouverte_schema.sql  —  Découverte des tables Clinisys (LECTURE SEULE)
-- À lancer sur la BASE DE TEST, avec l'utilisateur SQL en lecture seule.
-- Aucune instruction ne modifie la base : uniquement des SELECT.
-- Lancez chaque bloc séparément dans SSMS et exportez le résultat
-- (clic droit sur la grille > « Enregistrer les résultats sous » > CSV)
-- dans D:\Projet\Demandedesang\resultats\  (nom du fichier = n° du bloc).
-- ============================================================================

-- 0) Bases disponibles et base courante
SELECT name, create_date FROM sys.databases ORDER BY name;
SELECT DB_NAME() AS base_courante, SUSER_SNAME() AS utilisateur;

-- 1) Tables et vues dont le NOM évoque le sang, la pharmacie ou les demandes
SELECT s.name AS schema_name, o.name AS objet, o.type_desc,
       SUM(p.rows) AS nb_lignes
FROM sys.objects o
JOIN sys.schemas s ON s.schema_id = o.schema_id
LEFT JOIN sys.partitions p ON p.object_id = o.object_id AND p.index_id IN (0,1)
WHERE o.type IN ('U','V')
  AND (   o.name LIKE '%sang%'    OR o.name LIKE '%poche%'   OR o.name LIKE '%transf%'
       OR o.name LIKE '%group%'   OR o.name LIKE '%PSL%'     OR o.name LIKE '%CGR%'
       OR o.name LIKE '%PFC%'     OR o.name LIKE '%hemo%'    OR o.name LIKE '%depot%'
       OR o.name LIKE '%fourn%'   OR o.name LIKE '%recep%'   OR o.name LIKE '%prelev%'
       OR o.name LIKE '%demand%'  OR o.name LIKE '%DemSoin%' OR o.name LIKE '%stock%'
       OR o.name LIKE '%mouv%'    OR o.name LIKE '%sortie%'  OR o.name LIKE '%retour%'
       OR o.name LIKE '%serv%'    OR o.name LIKE '%venue%'   OR o.name LIKE '%admiss%')
GROUP BY s.name, o.name, o.type_desc
ORDER BY o.name;

-- 2) Colonnes dont le NOM évoque ces notions (repère les tables même mal nommées)
SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH
FROM INFORMATION_SCHEMA.COLUMNS
WHERE    COLUMN_NAME LIKE '%sang%'   OR COLUMN_NAME LIKE '%poche%'  OR COLUMN_NAME LIKE '%transf%'
      OR COLUMN_NAME LIKE '%group%'  OR COLUMN_NAME LIKE '%rhesus%' OR COLUMN_NAME LIKE '%ABO%'
      OR COLUMN_NAME LIKE '%depot%'  OR COLUMN_NAME LIKE '%fourn%'  OR COLUMN_NAME LIKE '%prelev%'
      OR COLUMN_NAME LIKE '%peremp%' OR COLUMN_NAME LIKE '%lot%'    OR COLUMN_NAME LIKE '%NumDoss%'
ORDER BY TABLE_NAME, COLUMN_NAME;

-- 3) Dépôts : retrouver le code du dépôt « Banque de sang »
--    (adaptez le nom de table si le bloc 1 en montre une autre, ex. Depot, Depots, Magasin)
-- SELECT * FROM dbo.Depot WHERE Designation LIKE '%sang%';

-- 4) Requêtes récentes exécutées par l'application Clinisys
--    Faites d'abord l'action dans Clinisys (ouvrir une demande de sang, une réception
--    dépôt fournisseur, un prélèvement), puis lancez ce bloc tout de suite.
--    Nécessite le droit VIEW SERVER STATE : s'il est refusé, ignorez ce bloc.
SELECT TOP 50 qs.last_execution_time, qs.execution_count,
       SUBSTRING(st.text, 1, 4000) AS requete
FROM sys.dm_exec_query_stats qs
CROSS APPLY sys.dm_exec_sql_text(qs.sql_handle) st
WHERE st.text LIKE '%sang%' OR st.text LIKE '%depot%' OR st.text LIKE '%fourn%'
   OR st.text LIKE '%prelev%' OR st.text LIKE '%demand%'
ORDER BY qs.last_execution_time DESC;

-- 5) Rechercher une valeur connue dans toutes les colonnes texte
--    Mettez un n° de dossier (ou un n° de poche) d'une vraie demande de sang de test.
--    Ce bloc peut être long : à lancer uniquement sur la base de test.
DECLARE @valeur nvarchar(100) = N'XXXXXX';   -- <<< à remplacer
DECLARE @sql nvarchar(max) = N'';
SELECT @sql = @sql + N'IF EXISTS (SELECT 1 FROM ' + QUOTENAME(TABLE_SCHEMA) + N'.' + QUOTENAME(TABLE_NAME)
     + N' WHERE ' + QUOTENAME(COLUMN_NAME) + N' = @v) PRINT ''' + TABLE_SCHEMA + N'.' + TABLE_NAME
     + N'.' + COLUMN_NAME + N''';' + CHAR(10)
FROM INFORMATION_SCHEMA.COLUMNS c
WHERE DATA_TYPE IN ('varchar','nvarchar','char','nchar')
  AND CHARACTER_MAXIMUM_LENGTH BETWEEN 4 AND 50
  AND EXISTS (SELECT 1 FROM INFORMATION_SCHEMA.TABLES t
              WHERE t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
                AND t.TABLE_TYPE = 'BASE TABLE');
EXEC sp_executesql @sql, N'@v nvarchar(100)', @v = @valeur;
-- Le résultat (onglet « Messages ») liste les tables.colonnes qui contiennent la valeur.

-- 6) Pour chaque table trouvée, sa structure complète et 20 lignes d'exemple
--    (remplacez NomTable ; masquez les noms de patients si vous partagez le résultat)
-- SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS
--  WHERE TABLE_NAME = 'NomTable' ORDER BY ORDINAL_POSITION;
-- SELECT TOP 20 * FROM dbo.NomTable ORDER BY 1 DESC;
