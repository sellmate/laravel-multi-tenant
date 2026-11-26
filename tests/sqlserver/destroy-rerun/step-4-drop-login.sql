-- SqlServerDriver::destroy() step 4 (SqlServerDriverTest::destroyStatements()['drop login']), name = lmt_t24
IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lmt_t24') DROP LOGIN [lmt_t24]
GO
