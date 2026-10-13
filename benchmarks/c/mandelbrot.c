#include <stdint.h>

int64_t {{symbol}}(int64_t w, int64_t h, int64_t maxIter, int32_t *accel_err)
{
    (void) accel_err;
    int64_t total = 0;
    for (int64_t py = 0; py < h; py++) {
        double y0 = (double) py / (double) h * 2.0 - 1.0;
        for (int64_t px = 0; px < w; px++) {
            double x0 = (double) px / (double) w * 3.5 - 2.5;
            double x = 0.0, y = 0.0;
            int64_t i = 0;
            while (x * x + y * y <= 4.0 && i < maxIter) {
                double t = x * x - y * y + x0;
                y = 2.0 * x * y + y0;
                x = t;
                i++;
            }
            total += i;
        }
    }
    return total;
}
