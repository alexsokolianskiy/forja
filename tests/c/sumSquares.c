#include <stdint.h>

int64_t {{symbol}}(int64_t n, int32_t *accel_err)
{
    int64_t sum = 0;
    for (int64_t i = 1; i <= n; i++) {
        int64_t square;
        if (__builtin_mul_overflow(i, i, &square) || __builtin_add_overflow(sum, square, &sum)) {
            *accel_err = 1; /* PHP would switch to float and then fail the int return type */
            return 0;
        }
    }
    return sum;
}
