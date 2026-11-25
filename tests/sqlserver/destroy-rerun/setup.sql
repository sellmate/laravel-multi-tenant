-- Tenant as SqlServerDriver::setup() leaves it: database, login, database user
USE [master];
GO
CREATE DATABASE [lmt_t24];
GO
CREATE LOGIN [lmt_t24] WITH PASSWORD = N'Lt-verify-24-tenant', CHECK_POLICY = OFF;
GO
USE [lmt_t24]; CREATE USER [lmt_t24] FOR LOGIN [lmt_t24];
GO
USE [master];
GO
