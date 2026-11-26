-- SqlServerDriver::destroy() step 2 (SqlServerDriverTest::destroyStatements()['kill']), name = lmt_t24
DECLARE @spid int, @kill nvarchar(30);
DECLARE tenant_sessions CURSOR LOCAL FAST_FORWARD FOR
    SELECT session_id FROM sys.dm_exec_sessions WHERE login_name = N'lmt_t24' AND is_user_process = 1 AND session_id <> @@SPID;
OPEN tenant_sessions;
FETCH NEXT FROM tenant_sessions INTO @spid;
WHILE @@FETCH_STATUS = 0
BEGIN
    SET @kill = N'KILL ' + CAST(@spid AS nvarchar(11));
    BEGIN TRY EXEC(@kill); END TRY
    BEGIN CATCH IF ERROR_NUMBER() <> 6106 THROW; END CATCH;
    FETCH NEXT FROM tenant_sessions INTO @spid;
END;
CLOSE tenant_sessions;
DEALLOCATE tenant_sessions;
GO
