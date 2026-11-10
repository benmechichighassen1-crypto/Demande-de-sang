-- ============================================================================
-- 03_creer_base_demandesang_app.sql
-- À lancer dans SSMS sur le serveur de TEST WIN-K4MS8TECCVA\SQL2019 (192.168.1.227,1416).
-- Crée la base propre à l'application « Demandes de sang » (tables ds_*),
-- à côté d'oncologie_app, avec le même classement que Clinisys.
-- Ne touche à aucune autre base. Les tables seront créées par l'application
-- (php artisan migrate --seed).
-- ============================================================================

IF @@SERVERNAME <> N'WIN-K4MS8TECCVA\SQL2019'
BEGIN
    RAISERROR(N'Mauvais serveur : ce script est prévu pour le serveur de test.', 16, 1);
    RETURN;
END;

IF DB_ID(N'demandesang_app') IS NULL
    CREATE DATABASE demandesang_app COLLATE SQL_Latin1_General_CP1256_CI_AS;
GO

-- Le compte « clinisys » utilisé par l'appli est sysadmin sur ce serveur de test :
-- il a déjà tous les droits sur la nouvelle base, rien d'autre à faire.
-- Si un autre compte doit être utilisé un jour, donnez-lui db_owner sur cette base seulement :
--   USE demandesang_app;
--   CREATE USER [compte] FOR LOGIN [compte];
--   ALTER ROLE db_owner ADD MEMBER [compte];

SELECT name, collation_name, create_date FROM sys.databases WHERE name = N'demandesang_app';
