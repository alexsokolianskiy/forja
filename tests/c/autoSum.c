#include <stdint.h>

int64_t {{symbol}}(int64_t n, int32_t *accel_err)
{
    int64_t sum = 0;
    for (int64_t i = 0; i < n; i++) {
        if (__builtin_add_overflow(sum, i, &sum)) {
            *accel_err = 1; /* PHP would switch to float and then fail the int return type */
            return 0;
        }
    }
    return sum;
}
