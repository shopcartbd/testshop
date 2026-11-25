<?php
declare(strict_types=1);
namespace AllStore\Models;

use AllStore\Core\Database;
use AllStore\Core\Helpers;

final class ReportRepository
{
    public static function dashboard(int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $since = gmdate('Y-m-d\TH:i:s\Z', time() - $days * 86400);
        $today = gmdate('Y-m-d\T00:00:00\Z');
        $week = gmdate('Y-m-d\T00:00:00\Z', time() - 7 * 86400);
        $month = gmdate('Y-m-01\T00:00:00\Z');
        $paid = "status NOT IN ('cancelled','refunded')";
        $m = [
            'total_revenue_cents' => (int)Database::value("SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE $paid"),
            'today_sales_cents' => (int)Database::value("SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE $paid AND placed_at >= :t", ['t'=>$today]),
            'week_sales_cents' => (int)Database::value("SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE $paid AND placed_at >= :t", ['t'=>$week]),
            'month_sales_cents' => (int)Database::value("SELECT COALESCE(SUM(total_cents),0) FROM orders WHERE $paid AND placed_at >= :t", ['t'=>$month]),
            'total_orders' => (int)Database::value('SELECT COUNT(*) FROM orders'),
            'pending_orders' => (int)Database::value("SELECT COUNT(*) FROM orders WHERE status IN ('pending','confirmed','processing','packed')"),
            'completed_orders' => (int)Database::value("SELECT COUNT(*) FROM orders WHERE status='delivered'"),
            'cancelled_orders' => (int)Database::value("SELECT COUNT(*) FROM orders WHERE status='cancelled'"),
            'total_customers' => (int)Database::value("SELECT COUNT(*) FROM profiles WHERE deleted_at IS NULL AND role_id NOT IN (SELECT id FROM roles WHERE slug IN ('super_admin','admin'))"),
            'new_customers' => (int)Database::value('SELECT COUNT(*) FROM profiles WHERE deleted_at IS NULL AND created_at >= :t', ['t'=>$since]),
            'total_products' => (int)Database::value("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND status='active'"),
            'low_stock' => (int)Database::value("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND status='active' AND stock > 0 AND stock <= low_stock_threshold"),
            'out_of_stock' => (int)Database::value("SELECT COUNT(*) FROM products WHERE deleted_at IS NULL AND status='active' AND stock <= 0"),
            'open_returns' => (int)Database::value("SELECT COUNT(*) FROM return_requests WHERE status='pending'"),
            'open_tickets' => (int)Database::value("SELECT COUNT(*) FROM support_tickets WHERE status IN ('open','pending')"),
        ];
        $m['average_order_value_cents'] = $m['total_orders'] > 0 ? (int)round($m['total_revenue_cents'] / $m['total_orders']) : 0;

        // Group in PHP: portable across PostgreSQL and SQLite (no date_trunc/strftime differences).
        $sales = []; $ordersChart = [];
        for ($i = $days - 1; $i >= 0; $i--) { $d = gmdate('Y-m-d', time() - $i * 86400); $sales[$d] = 0; $ordersChart[$d] = 0; }
        foreach (Database::all("SELECT placed_at,total_cents,status FROM orders WHERE placed_at >= :s", ['s'=>$since]) as $r) {
            $d = substr((string)$r['placed_at'], 0, 10);
            if (!array_key_exists($d, $sales)) { continue; }
            $ordersChart[$d]++;
            if (!in_array($r['status'], ['cancelled','refunded'], true)) { $sales[$d] += (int)$r['total_cents']; }
        }
        $topProducts = Database::all("SELECT oi.name, SUM(oi.quantity) AS units, SUM(oi.total_cents) AS revenue
            FROM order_items oi JOIN orders o ON o.id=oi.order_id
            WHERE o.placed_at >= :s AND o.status NOT IN ('cancelled','refunded')
            GROUP BY oi.product_id, oi.name ORDER BY units DESC LIMIT 8", ['s'=>$since]);
        $byCategory = Database::all("SELECT COALESCE(c.name,'Uncategorised') AS name, SUM(oi.total_cents) AS revenue, SUM(oi.quantity) AS units
            FROM order_items oi JOIN orders o ON o.id=oi.order_id
            LEFT JOIN products p ON p.id=oi.product_id LEFT JOIN categories c ON c.id=p.category_id
            WHERE o.placed_at >= :s AND o.status NOT IN ('cancelled','refunded')
            GROUP BY c.name ORDER BY revenue DESC LIMIT 8", ['s'=>$since]);
        $byPayment = Database::all("SELECT payment_method AS name, COUNT(*) AS orders, SUM(total_cents) AS revenue
            FROM orders WHERE placed_at >= :s AND status NOT IN ('cancelled','refunded') GROUP BY payment_method ORDER BY revenue DESC", ['s'=>$since]);
        $recentOrders = Database::all('SELECT id,order_number,customer_name,customer_email,status,payment_status,total_cents,placed_at FROM orders ORDER BY placed_at DESC LIMIT 8');
        $searchStats = Database::all('SELECT query, COUNT(*) AS searches, AVG(results_count) AS avg_results FROM search_logs WHERE created_at >= :s GROUP BY query ORDER BY searches DESC LIMIT 10', ['s'=>$since]);
        return [
            'metrics'=>$m, 'sales_chart'=>$sales, 'orders_chart'=>$ordersChart,
            'top_products'=>$topProducts, 'sales_by_category'=>$byCategory,
            'sales_by_payment'=>$byPayment, 'recent_orders'=>$recentOrders,
            'search_stats'=>$searchStats, 'days'=>$days,
        ];
    }
}
