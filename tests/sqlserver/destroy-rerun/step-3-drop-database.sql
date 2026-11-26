-- SqlServerDriver::destroy() step 3 (SqlServerDriverTest::destroyStatements()['drop database']), name = lmt_t24
USE [master];
IF DB_ID(N'lmt_t24') IS NOT NULL
BEGIN
    DECLARE @spid int, @kill nvarchar(30);
    DECLARE database_sessions CURSOR LOCAL FAST_FORWARD FOR
        SELECT session_id FROM sys.dm_exec_sessions WHERE database_id = DB_ID(N'lmt_t24') AND is_user_process = 1 AND session_id <> @@SPID;
    OPEN database_sessions;
    FETCH NEXT FROM database_sessions INTO @spid;
    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @kill = N'KILL ' + CAST(@spid AS nvarchar(11));
        BEGIN TRY EXEC(@kill); END TRY
        BEGIN CATCH IF ERROR_NUMBER() <> 6106 THROW; END CATCH;
        FETCH NEXT FROM database_sessions INTO @spid;
    END;
    CLOSE database_sessions;
    DEALLOCATE database_sessions;
    EXEC(N'ALTER DATABASE [lmt_t24] SET SINGLE_USER WITH ROLLBACK IMMEDIATE');
    EXEC(N'DROP DATABASE [lmt_t24]');
END
GO
