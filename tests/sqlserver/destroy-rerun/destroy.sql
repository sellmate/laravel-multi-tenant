-- Same statements as SqlServerDriver::destroy() (tests/Unit/SqlServerDriverTest::DESTROY_STATEMENTS), name = lmt_t24.
-- The session-ending KILL step that precedes them is not covered here.
IF DB_ID(N'lmt_t24') IS NOT NULL EXEC(N'USE [lmt_t24]; DROP USER IF EXISTS [lmt_t24];')
GO
IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lmt_t24') DROP LOGIN [lmt_t24]
GO
USE [master]; IF DB_ID(N'lmt_t24') IS NOT NULL EXEC(N'ALTER DATABASE [lmt_t24] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
GO
-- Laravel SqlServerGrammar::compileDropDatabaseIfExists
drop database if exists [lmt_t24]
GO
