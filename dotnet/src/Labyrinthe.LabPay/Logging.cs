using System;
using Labyrinthe.LabPay.Internal;

namespace Labyrinthe.LabPay;

/// <summary>Severity of an SDK log record.</summary>
public enum LabPayLogLevel
{
    /// <summary>Each attempt and response.</summary>
    Debug,

    /// <summary>API errors returned to your code.</summary>
    Information,

    /// <summary>Automatic retries.</summary>
    Warning,

    /// <summary>Network errors and timeouts returned to your code.</summary>
    Error,
}

/// <summary>Receives the SDK log records.</summary>
/// <remarks>
/// Records never contain the API key, a request body or query values (references, phone numbers).
/// <c>Labyrinthe.LabPay.AspNetCore</c> plugs <c>Microsoft.Extensions.Logging</c> in automatically (category
/// <c>Labyrinthe.LabPay.LabPayClient</c>); elsewhere, use <see cref="LabPayLogger.Create"/>.
/// </remarks>
public interface ILabPayLogger
{
    /// <summary>True when records of this level are wanted.</summary>
    /// <param name="level">Severity.</param>
    /// <returns>Whether to call <see cref="Log"/>.</returns>
    bool IsEnabled(LabPayLogLevel level);

    /// <summary>Writes one record.</summary>
    /// <param name="level">Severity.</param>
    /// <param name="message">Record text, already redacted.</param>
    void Log(LabPayLogLevel level, string message);
}

/// <summary>Ready-made <see cref="ILabPayLogger"/> implementations.</summary>
public static class LabPayLogger
{
    /// <summary>A logger that forwards every record at or above <paramref name="minimumLevel"/> to a delegate.</summary>
    /// <param name="write">Receives the level and the (redacted) message, e.g. <c>(level, message) =&gt; Console.Error.WriteLine(message)</c>.</param>
    /// <param name="minimumLevel">Lowest level forwarded.</param>
    /// <returns>The logger.</returns>
    public static ILabPayLogger Create(Action<LabPayLogLevel, string> write, LabPayLogLevel minimumLevel = LabPayLogLevel.Information)
    {
        Guard.NotNull(write, nameof(write));

        return new DelegateLogger(write, minimumLevel);
    }

    private sealed class DelegateLogger : ILabPayLogger
    {
        private readonly Action<LabPayLogLevel, string> write;
        private readonly LabPayLogLevel minimumLevel;

        public DelegateLogger(Action<LabPayLogLevel, string> write, LabPayLogLevel minimumLevel)
        {
            this.write = write;
            this.minimumLevel = minimumLevel;
        }

        public bool IsEnabled(LabPayLogLevel level) => level >= minimumLevel;

        public void Log(LabPayLogLevel level, string message)
        {
            if (IsEnabled(level))
            {
                write(level, message);
            }
        }
    }
}
