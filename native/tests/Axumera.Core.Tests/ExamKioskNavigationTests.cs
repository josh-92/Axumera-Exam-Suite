using Axumera.Core.Student;
using Xunit;

namespace Axumera.Core.Tests;

/// <summary>
/// Guards the Phase 4 security boundary decision: the review page is part of
/// the ACTIVE attempt and must never release kiosk mode; only a server-declared
/// terminal page (already_taken.php) or the exam-submitted web message may.
/// </summary>
public class ExamKioskNavigationTests
{
    [Theory]
    [InlineData(ExamKioskNavigation.ExamPortalPath)]
    [InlineData(ExamKioskNavigation.ReviewPath)]
    public void Exam_and_review_pages_are_part_of_the_active_attempt(string path)
    {
        Assert.True(ExamKioskNavigation.IsActiveAttemptPath(path));
    }

    [Fact]
    public void Review_page_never_releases_kiosk()
    {
        // The AXE 2.0 attempt stays 'in_progress' on the review page until
        // submit_exam.php finalizes it — releasing here would hand the student
        // a free window to browse elsewhere mid-exam.
        Assert.False(ExamKioskNavigation.IsPostExamPath(ExamKioskNavigation.ReviewPath));
    }

    [Fact]
    public void Login_and_waiting_pages_are_neither_active_nor_post_exam()
    {
        Assert.False(ExamKioskNavigation.IsActiveAttemptPath(ExamKioskNavigation.StudentLoginPath));
        Assert.False(ExamKioskNavigation.IsPostExamPath(ExamKioskNavigation.StudentLoginPath));
        Assert.False(ExamKioskNavigation.IsActiveAttemptPath(ExamKioskNavigation.WaitingPath));
        Assert.False(ExamKioskNavigation.IsPostExamPath(ExamKioskNavigation.WaitingPath));
    }

    [Fact]
    public void Already_taken_is_server_declared_terminal_state()
    {
        // already_taken.php is only reachable once the attempt is no longer
        // in_progress (examportal/submit redirect) — the one page navigation
        // that may release kiosk mode.
        Assert.True(ExamKioskNavigation.IsPostExamPath(ExamKioskNavigation.AlreadyTakenPath));
        Assert.False(ExamKioskNavigation.IsActiveAttemptPath(ExamKioskNavigation.AlreadyTakenPath));
    }

    [Fact]
    public void Null_or_unknown_paths_never_release_kiosk()
    {
        Assert.False(ExamKioskNavigation.IsPostExamPath(null));
        Assert.False(ExamKioskNavigation.IsPostExamPath("/adminpanel.php"));
        Assert.False(ExamKioskNavigation.IsActiveAttemptPath(null));
    }

    [Fact]
    public void Already_taken_releases_kiosk_whether_or_not_kiosk_is_active()
    {
        Assert.True(ExamKioskNavigation.IsKioskReleaseNavigation(ExamKioskNavigation.AlreadyTakenPath, true));
        Assert.True(ExamKioskNavigation.IsKioskReleaseNavigation(ExamKioskNavigation.AlreadyTakenPath, false));
    }

    [Fact]
    public void Login_page_releases_kiosk_only_when_kiosk_is_active()
    {
        // slogin.php is the post-submit landing page; if the exam-submitted
        // message was lost during page teardown, this fallback releases a
        // submitted student. The ordinary pre-exam login (kiosk inactive)
        // must never be treated as a release.
        Assert.True(ExamKioskNavigation.IsKioskReleaseNavigation(ExamKioskNavigation.StudentLoginPath, true));
        Assert.False(ExamKioskNavigation.IsKioskReleaseNavigation(ExamKioskNavigation.StudentLoginPath, false));
    }

    [Fact]
    public void Review_and_exam_pages_never_release_kiosk()
    {
        Assert.False(ExamKioskNavigation.IsKioskReleaseNavigation(ExamKioskNavigation.ReviewPath, true));
        Assert.False(ExamKioskNavigation.IsKioskReleaseNavigation(ExamKioskNavigation.ExamPortalPath, true));
        Assert.False(ExamKioskNavigation.IsKioskReleaseNavigation(ExamKioskNavigation.WaitingPath, true));
    }
}
