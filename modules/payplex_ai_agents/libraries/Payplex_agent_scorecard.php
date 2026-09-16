<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_scorecard
 *
 * Pure, dependency-free agent-performance scoring. Given the counts a model
 * aggregates from real activity (decision packets, council reviews, approved
 * knowledge, confidence), it produces one deterministic 0-100 score and a
 * rating, plus the component breakdown so the dashboard can show WHY.
 *
 * Design choices that matter for fairness:
 *  - An agent with no activity scores 0 and rates "no_data" — never a flattering
 *    default.
 *  - Approval rate only counts DECIDED packets (approved + rejected); packets
 *    still in review don't punish or flatter.
 *  - Rejections and returns pull the score down, so volume alone can't win.
 */
class Payplex_agent_scorecard
{
    /**
     * @param array $s counts: submitted, approved, rejected, returned,
     *                  reviews, knowledge_approved, avg_confidence (0..1)
     * @return array score(0..100), rating, components{}, and the echoed stats
     */
    public static function score(array $s)
    {
        $submitted = self::i($s, 'submitted');
        $approved  = self::i($s, 'approved');
        $rejected  = self::i($s, 'rejected');
        $returned  = self::i($s, 'returned');
        $reviews   = self::i($s, 'reviews');
        $knowledge = self::i($s, 'knowledge_approved');
        $conf      = isset($s['avg_confidence']) ? (float) $s['avg_confidence'] : 0.0;
        if ($conf < 0) { $conf = 0.0; } if ($conf > 1) { $conf = 1.0; }

        $activity = $submitted + $reviews + $knowledge;
        if ($activity === 0) {
            return array('score' => 0, 'rating' => 'no_data', 'components' => array(
                'quality' => 0.0, 'approval' => 0.0, 'contribution' => 0.0, 'confidence' => 0.0,
            ), 'stats' => $s);
        }

        // Approval quality: of DECIDED packets, share approved (0..1). No decided => neutral 0.5.
        $decided  = $approved + $rejected;
        $approval = $decided > 0 ? $approved / $decided : 0.5;

        // Quality: penalise returns (rework) relative to everything submitted.
        $quality = $submitted > 0 ? max(0.0, 1.0 - ($returned / $submitted)) : 0.5;

        // Contribution: volume of useful output, saturating (diminishing returns).
        // 10 decided/reviews/knowledge items ~= full marks.
        $useful       = $approved + $reviews + $knowledge;
        $contribution = self::saturate($useful, 10.0);

        // Weights sum to 1.0
        $score = 100.0 * (0.35 * $approval + 0.20 * $quality + 0.30 * $contribution + 0.15 * $conf);
        $score = (int) round(max(0.0, min(100.0, $score)));

        return array(
            'score'  => $score,
            'rating' => self::rating($score),
            'components' => array(
                'quality'      => round($quality, 3),
                'approval'     => round($approval, 3),
                'contribution' => round($contribution, 3),
                'confidence'   => round($conf, 3),
            ),
            'stats' => $s,
        );
    }

    /** x saturating toward 1.0 at x=cap (linear then capped — simple + deterministic). */
    private static function saturate($x, $cap)
    {
        if ($cap <= 0) { return 0.0; }
        $v = $x / $cap;
        return $v > 1.0 ? 1.0 : ($v < 0.0 ? 0.0 : $v);
    }

    public static function rating($score)
    {
        $score = (int) $score;
        if ($score >= 80) { return 'excellent'; }
        if ($score >= 60) { return 'good'; }
        if ($score >= 40) { return 'fair'; }
        return 'needs_improvement';
    }

    public static function ratingClass($rating)
    {
        switch ($rating) {
            case 'excellent': return 'success';
            case 'good':      return 'info';
            case 'fair':      return 'warning';
            case 'no_data':   return 'default';
            default:          return 'danger';
        }
    }

    private static function i($a, $k) { return isset($a[$k]) ? max(0, (int) $a[$k]) : 0; }
}
