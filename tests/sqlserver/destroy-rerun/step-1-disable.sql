-- SqlServerDriver::destroy() step 1 (SqlServerDriverTest::destroyStatements()['disable']), name = lmt_t24
IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lmt_t24') ALTER LOGIN [lmt_t24] DISABLE
GO
