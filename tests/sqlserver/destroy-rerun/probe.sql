-- Run WITHOUT -b: errors here are the observations the driver relies on, not failures.
SET NOCOUNT ON;
GO
PRINT '== P1: KILL of a session that does not exist (destroy() ignores exactly this error number)';
BEGIN TRY KILL 32000; END TRY
BEGIN CATCH PRINT 'P1 error number: ' + CAST(ERROR_NUMBER() AS nvarchar(11)) + ' - ' + ERROR_MESSAGE(); END CATCH;
GO
PRINT '== P2: ALTER LOGIN ... DISABLE on a missing login inside a false IF branch';
IF EXISTS (SELECT 1 FROM sys.server_principals WHERE name = N'lmt_missing') ALTER LOGIN [lmt_missing] DISABLE;
PRINT 'P2 batch ran without error';
GO
PRINT '== PROBES DONE';
GO
