namespace Axumera.Core.Common;

/// <summary>Result of an operation without a payload.</summary>
public readonly record struct Result
{
    public bool Succeeded { get; init; }
    public string? ErrorCode { get; init; }
    public string? ErrorMessage { get; init; }

    public static Result Ok() => new() { Succeeded = true };

    public static Result Fail(string errorCode, string errorMessage) =>
        new() { Succeeded = false, ErrorCode = errorCode, ErrorMessage = errorMessage };

    public override string ToString() =>
        Succeeded ? "OK" : $"FAILED ({ErrorCode}): {ErrorMessage}";
}

/// <summary>Result of an operation carrying a payload.</summary>
public readonly record struct Result<T>
{
    public bool Succeeded { get; init; }
    public T? Value { get; init; }
    public string? ErrorCode { get; init; }
    public string? ErrorMessage { get; init; }

    public static Result<T> Ok(T value) => new() { Succeeded = true, Value = value };

    public static Result<T> Fail(string errorCode, string errorMessage) =>
        new() { Succeeded = false, ErrorCode = errorCode, ErrorMessage = errorMessage };

    public Result ToResult() => Succeeded ? Result.Ok() : Result.Fail(ErrorCode ?? "error", ErrorMessage ?? "Unknown error");

    public override string ToString() =>
        Succeeded ? $"OK: {Value}" : $"FAILED ({ErrorCode}): {ErrorMessage}";
}
