using System;
using Microsoft.Extensions.Logging;

namespace Labyrinthe.LabPay.AspNetCore;

/// <summary>Sends the SDK log records (already redacted) to a <c>Microsoft.Extensions.Logging</c> <see cref="ILogger"/>.</summary>
public sealed class MicrosoftLoggerAdapter : ILabPayLogger
{
    private static readonly Action<ILogger, string, Exception?> LogDebug =
        LoggerMessage.Define<string>(LogLevel.Debug, new EventId(1, "LabPayDebug"), "{Message}");

    private static readonly Action<ILogger, string, Exception?> LogInformation =
        LoggerMessage.Define<string>(LogLevel.Information, new EventId(2, "LabPayApiError"), "{Message}");

    private static readonly Action<ILogger, string, Exception?> LogWarning =
        LoggerMessage.Define<string>(LogLevel.Warning, new EventId(3, "LabPayRetry"), "{Message}");

    private static readonly Action<ILogger, string, Exception?> LogError =
        LoggerMessage.Define<string>(LogLevel.Error, new EventId(4, "LabPayNetworkError"), "{Message}");

    private readonly ILogger logger;

    /// <summary>Wraps a logger.</summary>
    /// <param name="logger">Target logger, e.g. <c>ILogger&lt;LabPayClient&gt;</c>.</param>
    public MicrosoftLoggerAdapter(ILogger logger)
    {
        ArgumentNullException.ThrowIfNull(logger);
        this.logger = logger;
    }

    /// <inheritdoc/>
    public bool IsEnabled(LabPayLogLevel level) => logger.IsEnabled(ToLogLevel(level));

    /// <inheritdoc/>
    public void Log(LabPayLogLevel level, string message)
    {
        switch (level)
        {
            case LabPayLogLevel.Debug:
                LogDebug(logger, message, null);
                break;
            case LabPayLogLevel.Information:
                LogInformation(logger, message, null);
                break;
            case LabPayLogLevel.Warning:
                LogWarning(logger, message, null);
                break;
            default:
                LogError(logger, message, null);
                break;
        }
    }

    private static LogLevel ToLogLevel(LabPayLogLevel level) => level switch
    {
        LabPayLogLevel.Debug => LogLevel.Debug,
        LabPayLogLevel.Information => LogLevel.Information,
        LabPayLogLevel.Warning => LogLevel.Warning,
        _ => LogLevel.Error,
    };
}
