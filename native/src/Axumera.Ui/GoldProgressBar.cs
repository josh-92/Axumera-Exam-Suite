using System.ComponentModel;
using System.Drawing;

namespace Axumera.Ui;

/// <summary>Simple animated gold progress bar (0..1).</summary>
public sealed class GoldProgressBar : Control
{
    private float _progress;

    public GoldProgressBar()
    {
        SetStyle(ControlStyles.AllPaintingInWmPaint | ControlStyles.OptimizedDoubleBuffer | ControlStyles.UserPaint, true);
        Height = 8;
    }

    [DesignerSerializationVisibility(DesignerSerializationVisibility.Hidden)]
    public float Progress
    {
        get => _progress;
        set
        {
            _progress = Math.Clamp(value, 0f, 1f);
            Invalidate();
        }
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        base.OnPaint(e);
        var rect = new Rectangle(0, 0, Width - 1, Height - 1);
        using var track = new SolidBrush(Theme.LightGray);
        using var fill = new SolidBrush(Theme.Gold);
        e.Graphics.FillRectangle(track, rect);
        if (_progress > 0f)
        {
            var w = Math.Max(2, (int)(rect.Width * _progress));
            e.Graphics.FillRectangle(fill, new Rectangle(rect.X, rect.Y, w, rect.Height));
        }
    }
}
