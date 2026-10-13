#include "forja_rt.h"

static fj_val {{symbol}}_impl(fj_ctx *ctx, fj_val orders)
{
    if (orders.type != FJ_ARR) {
        fj_fail(ctx);
    }
    fj_arr *totals = fj_arr_new(ctx);
    size_t it = 0;
    fj_val key, *order;
    while (fj_next(orders.u.a, &it, &key, &order)) {
        if (order->type != FJ_ARR) {
            fj_fail(ctx);
        }
        fj_val *customer = fj_get_s(order->u.a, FJ_LIT("customer"));
        fj_val *amount = fj_get_s(order->u.a, FJ_LIT("amount"));
        fj_val *qty = fj_get_s(order->u.a, FJ_LIT("qty"));
        if (customer == NULL || amount == NULL || qty == NULL || customer->type != FJ_STR || qty->type != FJ_INT) {
            fj_fail(ctx);
        }
        double product;
        if (amount->type == FJ_FLOAT) {
            product = amount->u.d * (double) qty->u.i;
        } else if (amount->type == FJ_INT) {
            /* int * int stays an int in PHP and becomes a float only when added to the total */
            int64_t whole;
            if (__builtin_mul_overflow(amount->u.i, qty->u.i, &whole)) {
                fj_fail(ctx);
            }
            product = (double) whole;
        } else {
            fj_fail(ctx);
        }
        fj_val *total = fj_get_s(totals, customer->u.s);
        if (total != NULL && total->type != FJ_FLOAT) {
            fj_fail(ctx);
        }
        double before = total != NULL ? total->u.d : 0.0;
        fj_set_s(ctx, totals, customer->u.s, fj_float(before + product));
    }
    return fj_array(totals);
}
