using Axumera.Core.Student;
using Xunit;

namespace Axumera.Core.Tests;

public class ExamKioskPolicyTests
{
    [Fact]
    public void Starts_idle_and_enters_on_first_enter()
    {
        var policy = new ExamKioskPolicy();
        Assert.Equal(KioskState.Idle, policy.State);
        Assert.False(policy.IsKioskActive);

        Assert.True(policy.EnterKiosk());
        Assert.Equal(KioskState.Entered, policy.State);
        Assert.True(policy.IsKioskActive);
    }

    [Fact]
    public void Exit_kiosk_moves_to_exited_and_cannot_reenter()
    {
        var policy = new ExamKioskPolicy();
        policy.EnterKiosk();

        Assert.True(policy.ExitKiosk());
        Assert.Equal(KioskState.Exited, policy.State);
        Assert.False(policy.IsKioskActive);

        // The exam ended; the shell must never re-lock after submission/exit.
        Assert.False(policy.EnterKiosk());
        Assert.Equal(KioskState.Exited, policy.State);
        Assert.False(policy.ExitKiosk());
    }

    [Theory]
    [InlineData("tab_hidden", IntegrityEventOwner.Web)]
    [InlineData("window_blur", IntegrityEventOwner.Web)]
    [InlineData("fullscreen_exit", IntegrityEventOwner.Web)]
    [InlineData("copy_attempt", IntegrityEventOwner.Web)]
    [InlineData("paste_attempt", IntegrityEventOwner.Web)]
    [InlineData("context_menu_attempt", IntegrityEventOwner.Web)]
    [InlineData("devtools_shortcut_attempt", IntegrityEventOwner.Web)]
    public void In_page_events_are_owned_by_the_web_layer(string eventType, IntegrityEventOwner owner)
    {
        Assert.Equal(owner, ExamKioskPolicy.OwnerOf(eventType));
    }

    [Fact]
    public void Controlled_exit_is_the_native_owned_event()
    {
        Assert.Equal(IntegrityEventOwner.Native, ExamKioskPolicy.OwnerOf(ExamKioskPolicy.ControlledExitEvent));
    }

    [Fact]
    public void Unknown_events_fall_back_to_native_ownership()
    {
        Assert.Equal(IntegrityEventOwner.Native, ExamKioskPolicy.OwnerOf("something-else"));
    }

    [Fact]
    public void Native_focus_loss_is_coalesced_within_the_cooldown_window()
    {
        var policy = new ExamKioskPolicy();
        var now = new DateTimeOffset(2026, 8, 14, 12, 0, 0, TimeSpan.Zero);

        Assert.True(policy.ShouldRecordNativeFocusLoss(now));
        // Transient re-deactivation (a system notification) within the window
        // is NOT recorded again — harmless Windows events never pile up.
        Assert.False(policy.ShouldRecordNativeFocusLoss(now.AddMilliseconds(500)));
        Assert.False(policy.ShouldRecordNativeFocusLoss(now.AddSeconds(2)));

        // After the cooldown a new incident may be recorded.
        Assert.True(policy.ShouldRecordNativeFocusLoss(now.AddMilliseconds(ExamKioskPolicy.FocusLossCooldownMs + 1)));
    }
}
