using CareerEdge.Modules.Identity.Auth;
using CareerEdge.Modules.Identity.Domain;
using CareerEdge.Modules.Identity.Persistence;
using CareerEdge.SharedKernel;
using CareerEdge.SharedKernel.Http;
using FluentValidation;
using Microsoft.AspNetCore.Builder;
using Microsoft.AspNetCore.Http;
using Microsoft.AspNetCore.Identity;
using Microsoft.AspNetCore.Routing;
using Microsoft.EntityFrameworkCore;

namespace CareerEdge.Modules.Identity.Features;

/// <summary>POST /auth/login</summary>
public static class Login
{
    public sealed record Request(string? Email, string? Password);

    public sealed class Validator : AbstractValidator<Request>
    {
        public Validator()
        {
            RuleFor(r => r.Email).NotEmpty().WithMessage("Email is required.");
            RuleFor(r => r.Password).NotEmpty().WithMessage("Password is required.");
        }
    }

    internal static void Map(IEndpointRouteBuilder app) =>
        app.MapPost("/auth/login", Handle)
            .Validate<Request>()
            .RequireRateLimiting(RateLimitPolicies.Auth)
            .AllowAnonymous();

    private static async Task<IResult> Handle(m
        Request request,
        IdentityDbContext db,
        IPasswordHasher<User> passwordHasher,
        AuthSessionIssuer sessions,
        CancellationToken cancellationToken)
    {
        var email = User.NormalizeEmail(request.Email!);
        var user = await db.Users.FirstOrDefaultAsync(u => u.Email == email, cancellationToken);

        // Same error for "no such email" and "wrong password", so the API does not reveal
        // which emails are registered.
        if (user is null || !PasswordMatches(passwordHasher, user, request.Password!))
        {
            return Error.Unauthorized("INVALID_CREDENTIALS", "Email or password is incorrect").ToProblem();
        }

        var response = await sessions.IssueAsync(user, refreshFamilyId: null, cancellationToken);
        await db.SaveChangesAsync(cancellationToken);
        return Results.Ok(response);
    }

    private static bool PasswordMatches(IPasswordHasher<User> passwordHasher, User user, string password)
    {
        var result = passwordHasher.VerifyHashedPassword(user, user.PasswordHash, password);
        if (result == PasswordVerificationResult.SuccessRehashNeeded)
        {
            // The hash was made with older, weaker settings. Upgrade it now that we have the password.
            user.SetPasswordHash(passwordHasher.HashPassword(user, password));
        }

        return result != PasswordVerificationResult.Failed;
    }
}
