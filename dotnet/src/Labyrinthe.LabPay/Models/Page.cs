using System.Collections.Generic;

namespace Labyrinthe.LabPay;

/// <summary>One page of a paginated list.</summary>
/// <typeparam name="T">Item type.</typeparam>
public sealed class Page<T>
{
    internal Page(IReadOnlyList<T> data, int currentPage, int lastPage, int perPage, long total)
    {
        Data = data;
        CurrentPage = currentPage;
        LastPage = lastPage;
        PerPage = perPage;
        Total = total;
    }

    /// <summary>Items of this page only.</summary>
    public IReadOnlyList<T> Data { get; }

    /// <summary>Number of this page, from 1.</summary>
    public int CurrentPage { get; }

    /// <summary>Number of the last page.</summary>
    public int LastPage { get; }

    /// <summary>Items per page.</summary>
    public int PerPage { get; }

    /// <summary>Items of every page.</summary>
    public long Total { get; }

    /// <summary>True when a next page exists.</summary>
    public bool HasMore => CurrentPage < LastPage;

    /// <inheritdoc/>
    public override string ToString() =>
        $"Page {{ CurrentPage = {CurrentPage}, LastPage = {LastPage}, PerPage = {PerPage}, Total = {Total}, Items = {Data.Count} }}";
}
