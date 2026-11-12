using System;
using System.Collections.Generic;
using System.IO;
using System.Linq;
using Labyrinthe.LabPay.Internal;
using Xunit;
using YamlDotNet.RepresentationModel;

namespace Labyrinthe.LabPay.Tests;

/// <summary>Le SDK suit la spécification <c>../openapi/openapi.yaml</c>, source commune des SDK.</summary>
public class ContractTests
{
    /// <summary>Opérations appelées par le SDK (méthode + chemin relatif à /V2).</summary>
    private static readonly string[] SdkOperations =
    {
        "post /deposits",
        "post /payouts",
        "post /payouts/{id}/cancel",
        "post /refunds",
        "get /payments",
        "get /payments/{id}",
        "post /payments/{id}/sync",
        "get /countries",
        "get /providers",
        "get /availability",
        "post /predict-provider",
        "get /balances",
        "get /account",
        "post /account/webhook-secret/rotate",
        "post /account/test-key/rotate",
        "get /notifications",
        "post /notifications/{id}/resend",
    };

    private static YamlMappingNode Spec()
    {
        var directory = new DirectoryInfo(AppContext.BaseDirectory);
        while (directory is not null && !File.Exists(Path.Combine(directory.FullName, "openapi", "openapi.yaml")))
        {
            directory = directory.Parent;
        }

        Assert.NotNull(directory);
        using var reader = new StreamReader(Path.Combine(directory!.FullName, "openapi", "openapi.yaml"));
        var stream = new YamlStream();
        stream.Load(reader);
        return (YamlMappingNode)stream.Documents[0].RootNode;
    }

    private static List<string> EnumOf(YamlMappingNode spec, string schema)
    {
        var schemas = (YamlMappingNode)((YamlMappingNode)spec["components"])["schemas"];
        var values = (YamlSequenceNode)((YamlMappingNode)schemas[schema])["enum"];
        return values.Children.Select(v => ((YamlScalarNode)v).Value!).ToList();
    }

    [Fact]
    public void Covers_exactly_the_operations_of_the_spec()
    {
        var paths = (YamlMappingNode)Spec()["paths"];
        var operations = new List<string>();
        foreach (var path in paths.Children)
        {
            foreach (var method in ((YamlMappingNode)path.Value).Children)
            {
                operations.Add(((YamlScalarNode)method.Key).Value + " " + ((YamlScalarNode)path.Key).Value);
            }
        }

        Assert.Equal(SdkOperations.OrderBy(o => o, StringComparer.Ordinal), operations.OrderBy(o => o, StringComparer.Ordinal));
    }

    [Fact]
    public void Maps_every_documented_error_code_to_a_typed_exception()
    {
        var codes = EnumOf(Spec(), "ErrorCode");
        Assert.True(codes.Count > 20);
        foreach (var code in codes)
        {
            // Statut neutre : seule la table des codes peut donner une sous-classe.
            var body = Json.TryParse(System.Text.Encoding.UTF8.GetBytes("{\"message\":\"x\",\"code\":\"" + code + "\"}"));
            var error = ErrorMapper.FromResponse(418, body, new Dictionary<string, string>());
            Assert.True(error.GetType() != typeof(ApiException), code);
        }
    }

    [Fact]
    public void Keeps_the_enums_of_the_sdk()
    {
        var spec = Spec();
        Assert.Equal(EnumOf(spec, "PaymentStatus"), Values<PaymentStatus>(v => v.ToApiString()));
        Assert.Equal(EnumOf(spec, "Operation"), Values<Operation>(v => v.ToApiString()));
        Assert.Equal(EnumOf(spec, "NotificationStatus"), Values<NotificationStatus>(v => v.ToApiString()));
        Assert.Equal(EnumOf(spec, "WebhookEventType"), Values<WebhookEventType>(v => v.ToApiString()));
        Assert.Equal(EnumOf(spec, "Mode"), Values<LabPayMode>(v => v.ToApiString()));
    }

    private static List<string> Values<T>(Func<T, string> toApi)
        where T : struct, Enum =>
        Enum.GetValues<T>().Select(toApi).Where(v => v != "unknown").ToList();
}
